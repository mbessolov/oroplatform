<?php

namespace Oro\Bundle\ApiBundle\Tests\Unit\Request\JsonApi\JsonApiDocument;

use Oro\Bundle\ApiBundle\Config\ExpandRelatedEntitiesConfigExtra;
use Oro\Bundle\ApiBundle\Config\FilterFieldsConfigExtra;
use Oro\Bundle\ApiBundle\Exception\NotSupportedConfigOperationException;
use Oro\Bundle\ApiBundle\Metadata\AssociationMetadata;
use Oro\Bundle\ApiBundle\Metadata\EntityMetadata;
use Oro\Bundle\ApiBundle\Metadata\FieldMetadata;
use Oro\Bundle\ApiBundle\Model\Error;
use Oro\Bundle\ApiBundle\Model\ErrorSource;
use Oro\Bundle\ApiBundle\Request\DataType;
use Oro\Bundle\ApiBundle\Request\ExceptionTextExtractorInterface;
use Oro\Bundle\ApiBundle\Request\JsonApi\ErrorCompleter;
use Oro\Bundle\ApiBundle\Request\RequestType;
use Oro\Bundle\ApiBundle\Request\ValueNormalizer;
use Symfony\Component\HttpFoundation\Response;

class ErrorCompleterTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject|ExceptionTextExtractorInterface */
    protected $exceptionTextExtractor;

    /** @var \PHPUnit_Framework_MockObject_MockObject|ValueNormalizer */
    protected $valueNormalizer;

    /** @var RequestType */
    protected $requestType;

    /** @var ErrorCompleter */
    protected $errorCompleter;

    protected function setUp()
    {
        $this->exceptionTextExtractor = $this->createMock(ExceptionTextExtractorInterface::class);
        $this->valueNormalizer = $this->createMock(ValueNormalizer::class);
        $this->requestType = new RequestType([RequestType::REST, RequestType::JSON_API]);

        $this->errorCompleter = new ErrorCompleter($this->exceptionTextExtractor, $this->valueNormalizer);
    }

    public function testCompleteErrorWithoutInnerException()
    {
        $error = new Error();
        $expectedError = new Error();

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorWithInnerExceptionAndAlreadyCompletedProperties()
    {
        $exception = new \Exception('some exception');

        $error = new Error();
        $error->setStatusCode(400);
        $error->setCode('test code');
        $error->setTitle('test title');
        $error->setDetail('test detail');
        $error->setInnerException($exception);

        $expectedError = new Error();
        $expectedError->setStatusCode(400);
        $expectedError->setCode('test code');
        $expectedError->setTitle('test title');
        $expectedError->setDetail('test detail');
        $expectedError->setInnerException($exception);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorWithInnerExceptionAndExceptionTextExtractorReturnsNothing()
    {
        $exception = new \Exception('some exception');

        $error = new Error();
        $error->setInnerException($exception);

        $expectedError = new Error();
        $expectedError->setInnerException($exception);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorWithInnerException()
    {
        $exception = new \Exception('some exception');

        $error = new Error();
        $error->setInnerException($exception);

        $expectedError = new Error();
        $expectedError->setStatusCode(500);
        $expectedError->setCode('test code');
        $expectedError->setTitle('test title');
        $expectedError->setDetail('test detail');
        $expectedError->setInnerException($exception);

        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionStatusCode')
            ->with($this->identicalTo($exception))
            ->willReturn($expectedError->getStatusCode());
        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionCode')
            ->with($this->identicalTo($exception))
            ->willReturn($expectedError->getCode());
        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionType')
            ->with($this->identicalTo($exception))
            ->willReturn($expectedError->getTitle());
        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionText')
            ->with($this->identicalTo($exception))
            ->willReturn($expectedError->getDetail());

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorTitleByStatusCode()
    {
        $error = new Error();
        $error->setStatusCode(400);

        $expectedError = new Error();
        $expectedError->setStatusCode(400);
        $expectedError->setTitle(Response::$statusTexts[400]);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorTitleByUnknownStatusCode()
    {
        $error = new Error();
        $error->setStatusCode(1000);

        $expectedError = new Error();
        $expectedError->setStatusCode(1000);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorWithPropertyPathAndPointer()
    {
        $error = new Error();
        $errorSource = new ErrorSource();
        $errorSource->setPropertyPath('property');
        $errorSource->setPointer('pointer');
        $error->setDetail('test detail');
        $error->setSource($errorSource);

        $expectedError = new Error();
        $expectedErrorSource = new ErrorSource();
        $expectedErrorSource->setPropertyPath('property');
        $expectedErrorSource->setPointer('pointer');
        $expectedError->setDetail('test detail');
        $expectedError->setSource($expectedErrorSource);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorWithPropertyPathAndDetailEndsWithPoint()
    {
        $error = new Error();
        $error->setDetail('test detail.');
        $error->setSource(ErrorSource::createByPropertyPath('property'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail. Source: property.');

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    /**
     * @dataProvider completeErrorWithPropertyPathButWithoutMetadataDataProvider
     */
    public function testCompleteErrorWithPropertyPathButWithoutMetadata($property, $expectedResult)
    {
        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath($property));

        $expectedError = new Error();
        $expectedError->setDetail($expectedResult['detail']);

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function completeErrorWithPropertyPathButWithoutMetadataDataProvider()
    {
        return [
            [
                'id',
                [
                    'detail' => 'test detail. Source: id.',
                ]
            ],
            [
                'firstName',
                [
                    'detail' => 'test detail. Source: firstName.',
                ]
            ],
            [
                'user',
                [
                    'detail' => 'test detail. Source: user.',
                ]
            ],
            [
                'nonMappedPointer',
                [
                    'detail' => 'test detail. Source: nonMappedPointer.'
                ]
            ]
        ];
    }

    public function testCompleteErrorForIdentifier()
    {
        $metadata = new EntityMetadata();
        $metadata->setIdentifierFieldNames(['id']);
        $idField = new FieldMetadata();
        $idField->setName('id');
        $metadata->addField($idField);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('id'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/id'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForField()
    {
        $metadata = new EntityMetadata();
        $firstNameField = new FieldMetadata();
        $firstNameField->setName('firstName');
        $metadata->addField($firstNameField);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('firstName'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/firstName'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForToOneAssociation()
    {
        $metadata = new EntityMetadata();
        $userAssociation = new AssociationMetadata();
        $userAssociation->setName('user');
        $metadata->addAssociation($userAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('user'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/relationships/user/data'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForToManyAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/relationships/groups/data'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForChildOfToManyAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups.2'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/relationships/groups/data/2'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForNotMappedPointer()
    {
        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('notMappedPointer'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail. Source: notMappedPointer.');

        $this->errorCompleter->complete($error, $this->requestType, new EntityMetadata());
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $groupsAssociation->setCollapsed();
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForChildOfCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $groupsAssociation->setCollapsed();
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups.1'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups/1'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForChildFieldOfCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $groupsAssociation->setCollapsed();
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups.1.name'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups/1'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForNotCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForChildOfNotCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups.1'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups/1'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForChildFieldOfNotCollapsedArrayAssociation()
    {
        $metadata = new EntityMetadata();
        $groupsAssociation = new AssociationMetadata();
        $groupsAssociation->setName('groups');
        $groupsAssociation->setIsCollection(true);
        $groupsAssociation->setDataType('array');
        $metadata->addAssociation($groupsAssociation);

        $error = new Error();
        $error->setDetail('test detail');
        $error->setSource(ErrorSource::createByPropertyPath('groups.1.name'));

        $expectedError = new Error();
        $expectedError->setDetail('test detail');
        $expectedError->setSource(ErrorSource::createByPointer('/data/attributes/groups/1/name'));

        $this->errorCompleter->complete($error, $this->requestType, $metadata);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForExpandRelatedEntitiesConfigFilterConstraint()
    {
        $exception = new NotSupportedConfigOperationException(
            'Test\Class',
            ExpandRelatedEntitiesConfigExtra::NAME
        );

        $error = new Error();
        $error->setInnerException($exception);

        $expectedError = new Error();
        $expectedError->setStatusCode(400);
        $expectedError->setCode('test code');
        $expectedError->setTitle('filter constraint');
        $expectedError->setDetail('The filter is not supported.');
        $expectedError->setSource(ErrorSource::createByParameter('include'));
        $expectedError->setInnerException($exception);

        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionStatusCode')
            ->with($this->identicalTo($exception))
            ->willReturn(400);
        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionCode')
            ->with($this->identicalTo($exception))
            ->willReturn('test code');
        $this->exceptionTextExtractor->expects($this->never())
            ->method('getExceptionType');
        $this->exceptionTextExtractor->expects($this->never())
            ->method('getExceptionText');

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }

    public function testCompleteErrorForFilterFieldsConfigFilterConstraint()
    {
        $exception = new NotSupportedConfigOperationException(
            'Test\Class',
            FilterFieldsConfigExtra::NAME
        );

        $error = new Error();
        $error->setInnerException($exception);

        $expectedError = new Error();
        $expectedError->setStatusCode(400);
        $expectedError->setCode('test code');
        $expectedError->setTitle('filter constraint');
        $expectedError->setDetail('The filter is not supported.');
        $expectedError->setSource(ErrorSource::createByParameter('fields[test_entity]'));
        $expectedError->setInnerException($exception);

        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionStatusCode')
            ->with($this->identicalTo($exception))
            ->willReturn(400);
        $this->exceptionTextExtractor->expects($this->once())
            ->method('getExceptionCode')
            ->with($this->identicalTo($exception))
            ->willReturn('test code');
        $this->exceptionTextExtractor->expects($this->never())
            ->method('getExceptionType');
        $this->exceptionTextExtractor->expects($this->never())
            ->method('getExceptionText');

        $this->valueNormalizer->expects($this->once())
            ->method('normalizeValue')
            ->with(
                'Test\Class',
                DataType::ENTITY_TYPE,
                self::identicalTo($this->requestType)
            )
            ->willReturn('test_entity');

        $this->errorCompleter->complete($error, $this->requestType);
        self::assertEquals($expectedError, $error);
    }
}
