<?php
namespace Oro\Bundle\WorkflowBundle\Tests\Unit\Serializer\Normalizer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;

use Oro\Bundle\WorkflowBundle\Entity\ProcessTrigger;
use Oro\Bundle\WorkflowBundle\Model\ProcessData;
use Oro\Bundle\WorkflowBundle\Serializer\Normalizer\ProcessDataNormalizer;

class ProcessDataNormalizerTest extends \PHPUnit_Framework_TestCase
{
    const CLASS_NAME   = 'Oro\Bundle\WorkflowBundle\Model\ProcessData';
    const ENTITY_CLASS = 'Oro\Bundle\WorkflowBundle\Entity\WorkflowDefinition';
    const ENTITY_ID    = 'name';

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $registry;

    /**
     * @var ProcessDataNormalizer
     */
    protected $normalizer;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $serializer;

    protected function setUp()
    {
        $this->markTestIncomplete('Should be fixed in scope of CRM-763');

        $this->registry = $this->getMockBuilder('Doctrine\Bundle\DoctrineBundle\Registry')
            ->disableOriginalConstructor()
            ->getMock();

        $this->serializer = $this->getMockForAbstractClass('Symfony\Component\Serializer\SerializerInterface');
        $this->normalizer = new ProcessDataNormalizer($this->registry);
    }

    /**
     * @dataProvider denormalizeException
     */
    public function testDenormalizeException($data, $context, $exceptionType, $exceptionMessage)
    {
        $this->setExpectedException($exceptionType, $exceptionMessage);
        $this->normalizer->denormalize($data, self::CLASS_NAME, 'json', $context);
    }

    public function denormalizeException()
    {
        $unexpectedEvent = 'some-test-unexpected-event';
        return array(
            'empty entity' => array(
                'data'             => array('entity' => null),
                'context'          => array('processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_DELETE)),
                'exceptionType'    => 'Oro\Bundle\WorkflowBundle\Exception\InvalidParameterException',
                'exceptionMessage' => 'Invalid process job data for the delete event. Entity can not be empty.'
            ),
            'wrong entity format' => array(
                'data'             => array('entity' => 'some-string'),
                'context'          => array('processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_DELETE)),
                'exceptionType'    => 'Oro\Bundle\WorkflowBundle\Exception\InvalidParameterException',
                'exceptionMessage' => 'Invalid process job data for the delete event. Entity must be an object.'
            ),
            'unexpected event' => array(
                'data'             => array('entity' => 'some-string'),
                'context'          => array('processJob' => $this->getMockProcessJob($unexpectedEvent)),
                'exceptionType'    => 'Oro\Bundle\WorkflowBundle\Exception\InvalidParameterException',
                'exceptionMessage' => sprintf('Got invalid or unregister event "%s"', $unexpectedEvent)
            )
        );
    }

    /**
     * @dataProvider denormalizeDataProvider
     */
    public function testDenormalize($normalizedData, $denormalizedData, $context = array())
    {
        $this->normalizer->setSerializer($this->serializer);

        $this->assetReflectionMockForDenormalization($denormalizedData['entity']);

        $repository = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Entity\Repository\ProcessJobRepository')
            ->disableOriginalConstructor()
            ->getMock();

        if (empty($context) || $context['event'] == ProcessTrigger::EVENT_DELETE) {
            $repository->expects($this->never())
                ->method('findEntity');
            $this->registry->expects($this->never())
                ->method('getRepository');
        } else {
            $repository->expects($this->once())
                ->method('findEntity')
                ->will($this->returnValue($denormalizedData['entity']));

            $this->registry->expects($this->once())
                ->method('getRepository')
                ->with('OroWorkflowBundle:ProcessJob')
                ->will($this->returnValue($repository));
        }

        $this->assertEquals(
            $denormalizedData,
            $this->normalizer->denormalize($normalizedData, self::CLASS_NAME, 'json', $context)
        );
    }

    public function denormalizeDataProvider()
    {
        $denormalizedEntity = $this->createEntity();
        $normalizedEntity   = $this->normalizeEntity($denormalizedEntity);
        $data = array(
            'entity' => $normalizedEntity,
            'new' => array(
                'new_attribute1' => 'value1',
                'new_attribute2' => 'value2'
            ),
            'old' => array(
                'old_attribute1' => 'value1',
                'old_attribute2' => 'value2'
            )
        );
        return array(
            'without context' => array(
                'normalizedData'   => $data,
                'denormalizedData' => new ProcessData(array(
                    'entity' => $denormalizedEntity,
                    'new' => array(
                        'new_attribute1' => 'value1',
                        'new_attribute2' => 'value2'
                    ),
                    'old' => array(
                        'old_attribute1' => 'value1',
                        'old_attribute2' => 'value2'
                    )
                ))
            ),
            'create event' => array(
                'normalizedData'   => $data,
                'denormalizedData' => new ProcessData(array(
                    'entity' => $denormalizedEntity,
                    'old'    => null,
                    'new'    => null
                )),
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_CREATE,
                    'processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_CREATE)
                )
            ),
            'update event' => array(
                'normalizedData'   => $data,
                'denormalizedData' => new ProcessData(array(
                    'entity' => $denormalizedEntity,
                    'new' => array(
                        'new_attribute1' => 'value1',
                        'new_attribute2' => 'value2'
                    ),
                    'old' => array(
                        'old_attribute1' => 'value1',
                        'old_attribute2' => 'value2'
                    )
                )),
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_UPDATE,
                    'processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_UPDATE)
                )
            ),
            'delete event' => array(
                'normalizedData'   => $data,
                'denormalizedData' => new ProcessData(array(
                    'entity' => $denormalizedEntity,
                    'old'    => null,
                    'new'    => null
                )),
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_DELETE,
                    'processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_DELETE)
                )
            )
        );
    }

    /**
     * @dataProvider normalizeDataProvider
     */
    public function testNormalize($denormalizedValue, $normalizedValue, $context = array())
    {
        $this->normalizer->setSerializer($this->serializer);

        if (!empty($denormalizedValue['entity'])) {
            $this->assetReflectionMockForNormalization($denormalizedValue['entity'], $context);
        }

        $this->assertEquals($normalizedValue, $this->normalizer->normalize($denormalizedValue, 'json', $context));
    }

    public function normalizeDataProvider()
    {
        $simple     = array('test_attribute' => 'value');
        $complexity = array(
            'new' => array(
                'new_attribute1' => 'value1',
                'new_attribute2' => 'value2'
            ),
            'old' => array(
                'old_attribute1' => 'value1',
                'old_attribute2' => 'value2'
            )
        );

        $entity              = $this->createEntity();
        $normalizedForDelete = array('entity' => $this->normalizeEntity($entity));
        $normalizedForFull   = array_merge($normalizedForDelete, $complexity);
        $normalizedForCreate = array(
            'entity' => array(
                'className' => self::ENTITY_CLASS,
                'classData' => array(self::ENTITY_ID => $entity->getName())
            ),
        );
        $normalizedForUpdate = array_merge($normalizedForCreate, $complexity);
        $denormalizedEntity  = array_merge(array('entity' => $entity), $complexity);

        return array(
            'simple' => array(
                'denormalizedData' => new ProcessData($simple),
                'normalizedData'   => $simple,
            ),
            'more complexity' => array(
                'denormalizedData' => new ProcessData($complexity),
                'normalizedData'   => $complexity,
            ),
            'with entity without context' => array(
                'denormalizedData' => new ProcessData($denormalizedEntity),
                'normalizedData'   => $normalizedForFull,
            ),
            'with entity event create' => array(
                'denormalizedData' => new ProcessData($denormalizedEntity),
                'normalizedData'   => $normalizedForCreate,
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_CREATE,
                    'processJob' => $this->getMockProcessJob(
                        ProcessTrigger::EVENT_CREATE,
                        $entity->getName()
                    )
                )
            ),
            'with entity event update' => array(
                'denormalizedData' => new ProcessData($denormalizedEntity),
                'normalizedData'   => $normalizedForUpdate,
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_UPDATE,
                    'processJob' => $this->getMockProcessJob(
                        ProcessTrigger::EVENT_UPDATE,
                        $entity->getName()
                    )
                )
            ),
            'with entity event delete' => array(
                'denormalizedData' => new ProcessData($denormalizedEntity),
                'normalizedData'   => $normalizedForDelete,
                'context' => array(
                    'event'      => ProcessTrigger::EVENT_DELETE,
                    'processJob' => $this->getMockProcessJob(ProcessTrigger::EVENT_DELETE)
                )
            ),
        );
    }

    protected function createEntity()
    {
        $attributes = array(
            'createdAt'           => new \DateTime('yesterday'),
            'configuration'       => array('first', 'second', 'third'),
            'entityAcls'          => new ArrayCollection(array('acl1', 'acl2', 'acl3')),
            'entityAttributeName' => 'testEntityAttributeName',
            'label'               => 'testStepLabel',
            'name'                => 'testStepName',
            'relatedEntity'       => 'Oro\Bundle\WorkflowBundle\Entity\ProcessTrigger',
            'startStep'           => $this->getMock('Oro\Bundle\WorkflowBundle\Entity\WorkflowStep'),
            'steps'               => new ArrayCollection(array('step1', 'step2', 'step3')),
            'stepsDisplayOrdered' => false,
            'system'              => true,
            'updatedAt'           => new \DateTime('now'),
        );
        $reflection = new \ReflectionClass(self::ENTITY_CLASS);
        $entity     = $reflection->newInstanceWithoutConstructor();

        foreach ($attributes as $name => $value) {
            $reflectionProperty = new \ReflectionProperty($entity, $name);
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($entity, $value);
        }

        return $entity;
    }

    protected function normalizeEntity($entity)
    {
        $normalizedData = array(
            'className' => ClassUtils::getClass($entity),
            'classData' => array()
        );
        $reflection = new \ReflectionClass($entity);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $name = $property->getName();
            $reflection = new \ReflectionProperty($entity, $name);
            $reflection->setAccessible(true);
            $attribute = $reflection->getValue($entity);
            if (is_object($attribute)) {
                $attribute = array(ProcessDataNormalizer::SERIALIZED => base64_encode(serialize($attribute)));
            }
            $normalizedData['classData'][$name] = is_object($attribute) ? null : $attribute;
        }

        return $normalizedData;
    }

    /**
     * @dataProvider supportsDenormalizationDataProvider
     */
    public function testSupportsDenormalization($type, $expected)
    {
        $this->assertEquals($expected, $this->normalizer->supportsDenormalization('any_value', $type));
    }

    public function supportsDenormalizationDataProvider()
    {
        return array(
            'null'        => array(null, false),
            'string'      => array('string', false),
            'dateTime'    => array('DateTime', false),
            'processData' => array(self::CLASS_NAME, true),
            'stdClass'    => array('stdClass', false),
        );
    }

    /**
     * @dataProvider supportsNormalizationDataProvider
     */
    public function testSupportsNormalization($data, $expected)
    {
        $this->assertEquals($expected, $this->normalizer->supportsNormalization($data, 'anyValue'));
    }

    public function supportsNormalizationDataProvider()
    {
        return array(
            'null'        => array(null, false),
            'scalar'      => array('scalar', false),
            'datetime'    => array(new \DateTime(), false),
            'processData' => array(new ProcessData(), true),
            'stdClass'    => array(new \stdClass(), false),
        );
    }

    protected function assetReflectionMockForDenormalization($entity)
    {
        $className  = ClassUtils::getClass($entity);
        $reflection = new \ReflectionClass($entity);
        $properties = $reflection->getProperties();

        $classMetadata = $this->getMockBuilder('\Doctrine\ORM\Mapping\ClassMetadata')
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $entityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with($className)
            ->will($this->returnValue($classMetadata));
        $entityManager->expects($this->never())->method('getUnitOfWork');

        $classMetadata->expects($this->any())
            ->method('getReflectionClass')
            ->will($this->returnValue($entity));
        $classMetadata->expects($this->any())
            ->method('getReflectionProperty')
            ->will($this->returnCallback(
                function ($propertyName) use ($properties) {
                    foreach ($properties as $property) {
                        if ($property->getName() == $propertyName) {
                            return $property;
                        }
                    }
                    return false;
                }
            ));

        $this->registry->expects($this->any())
            ->method('getManager')
            ->will($this->returnValue($entityManager));
    }

    protected function assetReflectionMockForNormalization($entity, $context = array())
    {
        $className  = ClassUtils::getClass($entity);
        $fields     = $this->getFields($entity);

        $classMetadata = $this->getMockBuilder('Doctrine\ORM\Mapping\ClassMetadata')
            ->disableOriginalConstructor()
            ->getMock();

        $unitOfWork = $this->getMockBuilder('Doctrine\ORM\UnitOfWork')
            ->disableOriginalConstructor()
            ->getMock();
        $unitOfWork->expects($this->exactly(empty($context) ? 0 : 2))
            ->method('propertyChanged')
            ->will($this->returnSelf());

        $entityManager = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $entityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with($className)
            ->will($this->returnValue($classMetadata));
        $entityManager->expects($this->any())
            ->method('getUnitOfWork')
            ->will($this->returnValue($unitOfWork));

        if (empty($context) || $context['event'] == ProcessTrigger::EVENT_DELETE) {
            $classMetadata->expects($this->never())->method('getIdentifierFieldNames');
            $classMetadata->expects($this->any())
                ->method('getFieldNames')
                ->will($this->returnValue(array_keys($fields)));
            $classMetadata->expects($this->any())
                ->method('getFieldValue')
                ->will($this->returnCallback(function ($entity, $name) use ($fields) {
                    return isset($fields[$name]) ? $fields[$name] : null;
                }));
            $classMetadataFactory = $this->getMockBuilder('Doctrine\Common\Persistence\Mapping\ClassMetadataFactory')
                ->disableOriginalConstructor()
                ->getMock();
            $classMetadataFactory->expects($this->any())
                ->method('getAllMetadata')
                ->will($this->returnValue(array()));

            $entityManager->expects($this->any())
                ->method('getMetadataFactory')
                ->will($this->returnValue($classMetadataFactory));
        } else {
            $classMetadata->expects($this->once())
                ->method('getIdentifierFieldNames')
                ->will($this->returnValue(array(self::ENTITY_ID)));
            $classMetadata->expects($this->never())->method('getFieldNames');
            $classMetadata->expects($this->once())
                ->method('getFieldValue')
                ->will($this->returnValue($fields[self::ENTITY_ID]));
        }

        $this->registry->expects($this->any())
            ->method('getManager')
            ->will($this->returnValue($entityManager));
    }

    /**
     * @param string $event
     * @param string $id
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockProcessJob($event, $id = null)
    {
        $processTrigger = $this->getMock('Oro\Bundle\WorkflowBundle\Entity\ProcessTrigger');
        $processTrigger->expects($this->once())
            ->method('getEvent')
            ->will($this->returnValue($event));

        $processJob = $this->getMock('Oro\Bundle\WorkflowBundle\Entity\ProcessJob');
        $processJob->expects($this->once())
            ->method('getProcessTrigger')
            ->will($this->returnValue($processTrigger));
        $processJob->expects($this->any())
            ->method('getEntityId')
            ->will($this->returnValue($id));

        return $processJob;
    }

    /**
     * @param object $entity
     * @return array
     */
    protected function getFields($entity)
    {
        $reflection = new \ReflectionClass($entity);
        $properties = $reflection->getProperties();
        $fields = array();

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $name = $property->getName();
            $fields[$name] = $property->getValue($entity);
        }

        return $fields;
    }
}
