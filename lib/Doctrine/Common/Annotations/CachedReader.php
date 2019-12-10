<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\Common\Annotations;

use Doctrine\Common\Annotations\Cache\CacheItemPool;
use Doctrine\Common\Cache\Cache;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use TypeError;
use function get_class;
use function gettype;
use function is_object;
use function sprintf;
use function trigger_error;
use const E_USER_DEPRECATED;

/**
 * A cache aware annotation reader.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
final class CachedReader implements Reader
{
    /**
     * @var Reader
     */
    private $delegate;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var boolean
     */
    private $debug;

    /**
     * @var array
     */
    private $loadedAnnotations = [];

    /**
     * @param CacheItemPoolInterface $cache
     * @param bool $debug
     */
    public function __construct(Reader $reader, $cache, $debug = false)
    {
        if ($cache instanceof Cache) {
            @trigger_error(sprintf('Passing an instance of %s as $cache is deprecated. Please pass a PSR-6 cache instead.', get_class($cache)), E_USER_DEPRECATED);

            $cache = new CacheItemPool($cache);
        } elseif (!$cache instanceof CacheItemPoolInterface) {
            throw new TypeError(sprintf('Expected $cache to be an instance of %s, got %s.', CacheItemPoolInterface::class, is_object($cache) ? get_class($cache) : gettype($cache)));
        }

        $this->delegate = $reader;
        $this->cache = $cache;
        $this->debug = (boolean) $debug;
    }

    /**
     * {@inheritDoc}
     */
    public function getClassAnnotations(ReflectionClass $class)
    {
        $cacheKey = str_replace('\\', '.', $class->getName());

        return $this->loadedAnnotations[$cacheKey] = $this->fetchCachedAnnotations($cacheKey, $class, function () use ($class) {
            return $this->delegate->getClassAnnotations($class);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getClassAnnotation(ReflectionClass $class, $annotationName)
    {
        foreach ($this->getClassAnnotations($class) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getPropertyAnnotations(\ReflectionProperty $property)
    {
        $class = $property->getDeclaringClass();
        $cacheKey = str_replace('\\', '.', $class->getName()).'$'.$property->getName();

        return $this->loadedAnnotations[$cacheKey] = $this->fetchCachedAnnotations($cacheKey, $class, function () use ($property) {
            return $this->delegate->getPropertyAnnotations($property);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getPropertyAnnotation(\ReflectionProperty $property, $annotationName)
    {
        foreach ($this->getPropertyAnnotations($property) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getMethodAnnotations(\ReflectionMethod $method)
    {
        $class = $method->getDeclaringClass();
        $cacheKey = str_replace('\\', '.', $class->getName()).'#'.$method->getName();

        return $this->loadedAnnotations[$cacheKey] = $this->fetchCachedAnnotations($cacheKey, $class, function () use ($method) {
            return $this->delegate->getMethodAnnotations($method);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getMethodAnnotation(\ReflectionMethod $method, $annotationName)
    {
        foreach ($this->getMethodAnnotations($method) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * Clears loaded annotations.
     *
     * @return void
     */
    public function clearLoadedAnnotations()
    {
        $this->loadedAnnotations = [];
    }

    private function fetchCachedAnnotations(string $cacheKey, ReflectionClass $class, callable $delegate): array
    {
        if (isset($this->loadedAnnotations[$cacheKey])) {
            return $this->loadedAnnotations[$cacheKey];
        }

        $annotationsItem = $this->cache->getItem($cacheKey);
        if ($this->debug) {
            $debugItem = $this->cache->getItem('[C]'.$cacheKey);
        }


        if ($annotationsItem->isHit()) {
            if (!$this->debug) {
                return $annotationsItem->get();
            }

            $lastModification = $this->getLastModification($class);
            if ($lastModification === 0 || ($debugItem->isHit() && $debugItem->get() >= $lastModification)) {
                return $annotationsItem->get();
            }
        }

        $data = $delegate();
        $annotationsItem->set($data);

        $this->cache->save($annotationsItem);
        if ($this->debug) {
            $debugItem->set(time());
            $this->cache->save($debugItem);
        }

        return $data;
    }

    /**
     * Returns the time the class was last modified, testing traits and parents
     */
    private function getLastModification(ReflectionClass $class): int
    {
        $filename = $class->getFileName();
        $parent   = $class->getParentClass();

        $lastModification =  max(array_merge(
            [$filename ? filemtime($filename) : 0],
            array_map([$this, 'getTraitLastModificationTime'], $class->getTraits()),
            array_map([$this, 'getLastModification'], $class->getInterfaces()),
            $parent ? [$this->getLastModification($parent)] : []
        ));

        assert($lastModification !== false);

        return $lastModification;
    }

    private function getTraitLastModificationTime(ReflectionClass $reflectionTrait): int
    {
        $fileName = $reflectionTrait->getFileName();

        $lastModificationTime = max(array_merge(
            [$fileName ? filemtime($fileName) : 0],
            array_map([$this, 'getTraitLastModificationTime'], $reflectionTrait->getTraits())
        ));

        assert($lastModificationTime !== false);

        return $lastModificationTime;
    }
}
