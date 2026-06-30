<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace OpenNfseVendor\Symfony\Component\VarDumper\Cloner;

use OpenNfseVendor\Symfony\Component\VarDumper\Caster\Caster;
use OpenNfseVendor\Symfony\Component\VarDumper\Exception\ThrowingCasterException;
/**
 * AbstractCloner implements a generic caster mechanism for objects and resources.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
abstract class AbstractCloner implements ClonerInterface
{
    public static array $defaultCasters = ['__PHP_Incomplete_Class' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\Caster', 'castPhpIncompleteClass'], 'AddressInfo' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AddressInfoCaster', 'castAddressInfo'], 'Socket' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SocketCaster', 'castSocket'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\CutStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'castStub'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\CutArrayStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'castCutArray'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\ConstStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'castStub'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\EnumStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'castEnum'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\ScalarStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'castScalar'], 'Fiber' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\FiberCaster', 'castFiber'], 'Closure' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClosure'], 'Generator' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castGenerator'], 'ReflectionType' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castType'], 'ReflectionAttribute' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castAttribute'], 'ReflectionGenerator' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castReflectionGenerator'], 'ReflectionClass' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClass'], 'ReflectionClassConstant' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castClassConstant'], 'ReflectionFunctionAbstract' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castFunctionAbstract'], 'ReflectionMethod' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castMethod'], 'ReflectionParameter' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castParameter'], 'ReflectionProperty' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castProperty'], 'ReflectionReference' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castReference'], 'ReflectionExtension' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castExtension'], 'ReflectionZendExtension' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ReflectionCaster', 'castZendExtension'], 'OpenNfseVendor\Doctrine\Common\Persistence\ObjectManager' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Doctrine\Common\Proxy\Proxy' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castCommonProxy'], 'OpenNfseVendor\Doctrine\ORM\Proxy\Proxy' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castOrmProxy'], 'OpenNfseVendor\Doctrine\ORM\PersistentCollection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DoctrineCaster', 'castPersistentCollection'], 'OpenNfseVendor\Doctrine\Persistence\ObjectManager' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'DOMException' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castException'], 'OpenNfseVendor\Dom\Exception' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castException'], 'DOMStringList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMNameList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMImplementation' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castImplementation'], 'OpenNfseVendor\Dom\Implementation' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castImplementation'], 'DOMImplementationList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMNode' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\Node' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMNameSpaceNode' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMDocument' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDocument'], 'OpenNfseVendor\Dom\XMLDocument' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castXMLDocument'], 'OpenNfseVendor\Dom\HTMLDocument' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castHTMLDocument'], 'DOMNodeList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\NodeList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMNamedNodeMap' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\DTDNamedNodeMap' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'DOMXPath' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\XPath' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\HTMLCollection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'OpenNfseVendor\Dom\TokenList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DOMCaster', 'castDom'], 'XMLReader' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\XmlReaderCaster', 'castXmlReader'], 'ErrorException' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castErrorException'], 'Exception' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castException'], 'Error' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castError'], 'OpenNfseVendor\Symfony\Bridge\Monolog\Logger' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Symfony\Component\DependencyInjection\ContainerInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Symfony\Component\EventDispatcher\EventDispatcherInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Symfony\Component\HttpClient\AmpHttpClient' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'OpenNfseVendor\Symfony\Component\HttpClient\CurlHttpClient' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'OpenNfseVendor\Symfony\Component\HttpClient\NativeHttpClient' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClient'], 'OpenNfseVendor\Symfony\Component\HttpClient\Response\AmpResponse' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'OpenNfseVendor\Symfony\Component\HttpClient\Response\AmpResponseV4' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'OpenNfseVendor\Symfony\Component\HttpClient\Response\AmpResponseV5' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'OpenNfseVendor\Symfony\Component\HttpClient\Response\CurlResponse' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'OpenNfseVendor\Symfony\Component\HttpClient\Response\NativeResponse' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castHttpClientResponse'], 'OpenNfseVendor\Symfony\Component\HttpFoundation\Request' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castRequest'], 'OpenNfseVendor\Symfony\Component\Uid\Ulid' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castUlid'], 'OpenNfseVendor\Symfony\Component\Uid\Uuid' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castUuid'], 'OpenNfseVendor\Symfony\Component\VarExporter\Internal\LazyObjectState' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SymfonyCaster', 'castLazyObjectState'], 'OpenNfseVendor\Symfony\Component\VarDumper\Exception\ThrowingCasterException' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castThrowingCasterException'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\TraceStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castTraceStub'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\FrameStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castFrameStub'], 'OpenNfseVendor\Symfony\Component\VarDumper\Cloner\AbstractCloner' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Symfony\Component\ErrorHandler\Exception\FlattenException' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castFlattenException'], 'OpenNfseVendor\Symfony\Component\ErrorHandler\Exception\SilencedErrorContext' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ExceptionCaster', 'castSilencedErrorContext'], 'OpenNfseVendor\Imagine\Image\ImageInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ImagineCaster', 'castImage'], 'OpenNfseVendor\Ramsey\Uuid\UuidInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\UuidCaster', 'castRamseyUuid'], 'OpenNfseVendor\ProxyManager\Proxy\ProxyInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ProxyManagerCaster', 'castProxy'], 'PHPUnit_Framework_MockObject_MockObject' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\PHPUnit\Framework\MockObject\MockObject' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\PHPUnit\Framework\MockObject\Stub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Prophecy\Prophecy\ProphecySubjectInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'OpenNfseVendor\Mockery\MockInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'], 'PDO' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\PdoCaster', 'castPdo'], 'PDOStatement' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\PdoCaster', 'castPdoStatement'], 'AMQPConnection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castConnection'], 'AMQPChannel' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castChannel'], 'AMQPQueue' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castQueue'], 'AMQPExchange' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castExchange'], 'AMQPEnvelope' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\AmqpCaster', 'castEnvelope'], 'ArrayObject' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castArrayObject'], 'ArrayIterator' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castArrayIterator'], 'SplDoublyLinkedList' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castDoublyLinkedList'], 'SplFileInfo' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castFileInfo'], 'SplFileObject' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castFileObject'], 'SplHeap' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castHeap'], 'SplObjectStorage' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castObjectStorage'], 'SplPriorityQueue' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castHeap'], 'OuterIterator' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castOuterIterator'], 'WeakMap' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castWeakMap'], 'WeakReference' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SplCaster', 'castWeakReference'], 'Redis' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedis'], 'OpenNfseVendor\Relay\Relay' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedis'], 'RedisArray' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedisArray'], 'RedisCluster' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RedisCaster', 'castRedisCluster'], 'DateTimeInterface' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DateCaster', 'castDateTime'], 'DateInterval' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DateCaster', 'castInterval'], 'DateTimeZone' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DateCaster', 'castTimeZone'], 'DatePeriod' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DateCaster', 'castPeriod'], 'GMP' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\GmpCaster', 'castGmp'], 'MessageFormatter' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\IntlCaster', 'castMessageFormatter'], 'NumberFormatter' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\IntlCaster', 'castNumberFormatter'], 'IntlTimeZone' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlTimeZone'], 'IntlCalendar' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlCalendar'], 'IntlDateFormatter' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\IntlCaster', 'castIntlDateFormatter'], 'Memcached' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\MemcachedCaster', 'castMemcached'], 'OpenNfseVendor\Ds\Collection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DsCaster', 'castCollection'], 'OpenNfseVendor\Ds\Map' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DsCaster', 'castMap'], 'OpenNfseVendor\Ds\Pair' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DsCaster', 'castPair'], 'OpenNfseVendor\Symfony\Component\VarDumper\Caster\DsPairStub' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\DsCaster', 'castPairStub'], 'mysqli_driver' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\MysqliCaster', 'castMysqliDriver'], 'CurlHandle' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\CurlCaster', 'castCurl'], 'OpenNfseVendor\Dba\Connection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castDba'], ':dba' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castDba'], ':dba persistent' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castDba'], 'GdImage' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\GdCaster', 'castGd'], 'SQLite3Result' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\SqliteCaster', 'castSqlite3Result'], 'OpenNfseVendor\PgSql\Lob' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castLargeObject'], 'OpenNfseVendor\PgSql\Connection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castLink'], 'OpenNfseVendor\PgSql\Result' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\PgSqlCaster', 'castResult'], ':process' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castProcess'], ':stream' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStream'], 'OpenSSLAsymmetricKey' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\OpenSSLCaster', 'castOpensslAsymmetricKey'], 'OpenSSLCertificateSigningRequest' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\OpenSSLCaster', 'castOpensslCsr'], 'OpenSSLCertificate' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\OpenSSLCaster', 'castOpensslX509'], ':persistent stream' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStream'], ':stream-context' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\ResourceCaster', 'castStreamContext'], 'XmlParser' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\XmlResourceCaster', 'castXml'], 'RdKafka' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castRdKafka'], 'OpenNfseVendor\RdKafka\Conf' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castConf'], 'OpenNfseVendor\RdKafka\KafkaConsumer' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castKafkaConsumer'], 'OpenNfseVendor\RdKafka\Metadata\Broker' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castBrokerMetadata'], 'OpenNfseVendor\RdKafka\Metadata\Collection' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castCollectionMetadata'], 'OpenNfseVendor\RdKafka\Metadata\Partition' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castPartitionMetadata'], 'OpenNfseVendor\RdKafka\Metadata\Topic' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicMetadata'], 'OpenNfseVendor\RdKafka\Message' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castMessage'], 'OpenNfseVendor\RdKafka\Topic' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopic'], 'OpenNfseVendor\RdKafka\TopicPartition' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicPartition'], 'OpenNfseVendor\RdKafka\TopicConf' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\RdKafkaCaster', 'castTopicConf'], 'OpenNfseVendor\FFI\CData' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\FFICaster', 'castCTypeOrCData'], 'OpenNfseVendor\FFI\CType' => ['OpenNfseVendor\Symfony\Component\VarDumper\Caster\FFICaster', 'castCTypeOrCData']];
    protected int $maxItems = 2500;
    protected int $maxString = -1;
    protected int $minDepth = 1;
    /**
     * @var array<string, list<callable>>
     */
    private array $casters = [];
    /**
     * @var callable|null
     */
    private $prevErrorHandler;
    private array $classInfo = [];
    private int $filter = 0;
    /**
     * @param callable[]|null $casters A map of casters
     *
     * @see addCasters
     */
    public function __construct(?array $casters = null)
    {
        $this->addCasters($casters ?? static::$defaultCasters);
    }
    /**
     * Adds casters for resources and objects.
     *
     * Maps resources or object types to a callback.
     * Use types as keys and callable casters as values.
     * Prefix types with `::`,
     * see e.g. self::$defaultCasters.
     *
     * @param array<string, callable> $casters A map of casters
     */
    public function addCasters(array $casters): void
    {
        foreach ($casters as $type => $callback) {
            $this->casters[$type][] = $callback;
        }
    }
    /**
     * Adds default casters for resources and objects.
     *
     * Maps resources or object types to a callback.
     * Use types as keys and callable casters as values.
     * Prefix types with `::`,
     * see e.g. self::$defaultCasters.
     *
     * @param array<string, callable> $casters A map of casters
     */
    public static function addDefaultCasters(array $casters): void
    {
        self::$defaultCasters = [...self::$defaultCasters, ...$casters];
    }
    /**
     * Sets the maximum number of items to clone past the minimum depth in nested structures.
     */
    public function setMaxItems(int $maxItems): void
    {
        $this->maxItems = $maxItems;
    }
    /**
     * Sets the maximum cloned length for strings.
     */
    public function setMaxString(int $maxString): void
    {
        $this->maxString = $maxString;
    }
    /**
     * Sets the minimum tree depth where we are guaranteed to clone all the items.  After this
     * depth is reached, only setMaxItems items will be cloned.
     */
    public function setMinDepth(int $minDepth): void
    {
        $this->minDepth = $minDepth;
    }
    /**
     * Clones a PHP variable.
     *
     * @param int $filter A bit field of Caster::EXCLUDE_* constants
     */
    public function cloneVar(mixed $var, int $filter = 0): Data
    {
        $this->prevErrorHandler = set_error_handler(function ($type, $msg, $file, $line, $context = []) {
            if (\E_RECOVERABLE_ERROR === $type || \E_USER_ERROR === $type) {
                // Cloner never dies
                throw new \ErrorException($msg, 0, $type, $file, $line);
            }
            if ($this->prevErrorHandler) {
                return ($this->prevErrorHandler)($type, $msg, $file, $line, $context);
            }
            return \false;
        });
        $this->filter = $filter;
        if ($gc = gc_enabled()) {
            gc_disable();
        }
        try {
            return new Data($this->doClone($var));
        } finally {
            if ($gc) {
                gc_enable();
            }
            restore_error_handler();
            $this->prevErrorHandler = null;
        }
    }
    /**
     * Effectively clones the PHP variable.
     */
    abstract protected function doClone(mixed $var): array;
    /**
     * Casts an object to an array representation.
     *
     * @param bool $isNested True if the object is nested in the dumped structure
     */
    protected function castObject(Stub $stub, bool $isNested): array
    {
        $obj = $stub->value;
        $class = $stub->class;
        if (str_contains($class, "@anonymous\x00")) {
            $stub->class = get_debug_type($obj);
        }
        if (isset($this->classInfo[$class])) {
            [$i, $parents, $hasDebugInfo, $fileInfo] = $this->classInfo[$class];
        } else {
            $i = 2;
            $parents = [$class];
            $hasDebugInfo = method_exists($class, '__debugInfo');
            foreach (class_parents($class) as $p) {
                $parents[] = $p;
                ++$i;
            }
            foreach (class_implements($class) as $p) {
                $parents[] = $p;
                ++$i;
            }
            $parents[] = '*';
            $r = new \ReflectionClass($class);
            $fileInfo = $r->isInternal() || $r->isSubclassOf(Stub::class) ? [] : ['file' => $r->getFileName(), 'line' => $r->getStartLine()];
            $this->classInfo[$class] = [$i, $parents, $hasDebugInfo, $fileInfo];
        }
        $stub->attr += $fileInfo;
        $a = Caster::castObject($obj, $class, $hasDebugInfo, $stub->class);
        try {
            while ($i--) {
                if (!empty($this->casters[$p = $parents[$i]])) {
                    foreach ($this->casters[$p] as $callback) {
                        $a = $callback($obj, $a, $stub, $isNested, $this->filter);
                    }
                }
            }
        } catch (\Exception $e) {
            $a = [(Stub::TYPE_OBJECT === $stub->type ? Caster::PREFIX_VIRTUAL : '') . '⚠' => new ThrowingCasterException($e)] + $a;
        }
        return $a;
    }
    /**
     * Casts a resource to an array representation.
     *
     * @param bool $isNested True if the object is nested in the dumped structure
     */
    protected function castResource(Stub $stub, bool $isNested): array
    {
        $a = [];
        $res = $stub->value;
        $type = $stub->class;
        try {
            if (!empty($this->casters[':' . $type])) {
                foreach ($this->casters[':' . $type] as $callback) {
                    $a = $callback($res, $a, $stub, $isNested, $this->filter);
                }
            }
        } catch (\Exception $e) {
            $a = [(Stub::TYPE_OBJECT === $stub->type ? Caster::PREFIX_VIRTUAL : '') . '⚠' => new ThrowingCasterException($e)] + $a;
        }
        return $a;
    }
}
