<?php

namespace ride\web\rest\controller;

use ride\library\config\exception\ConfigException;
use ride\library\config\parser\JsonParser;
use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\http\jsonapi\JsonApi;
use ride\library\http\Header;
use ride\library\http\Response;

use ride\service\MimeService;

use ride\web\mvc\controller\AbstractController;

/**
 * Abstract controller for a JSON API
 */
abstract class AbstractJsonApiController extends AbstractController {

    const EXTENSION_BULK = 'bulk';

    protected $api;

    protected $jsonParser;

    protected $mimeService;

    protected $supportedExtensions;

    protected $requestedExtensions;

    protected $usedExtensions;

    protected $document;

    /**
     * Constructs a new JSON API controller
     * @param \ride\library\http\jsonapi\JsonApi $api
     * @return null
     */
    public function __construct(JsonApi $jsonApi, JsonParser $jsonParser, MimeService $mimeService) {
        $this->api = $jsonApi;
        $this->jsonParser = $jsonParser;
        $this->mimeService = $mimeService;

        $this->supportedExtensions = array();
        $this->requestedExtensions = array();
        $this->usedExtensions = array();

        $this->initialize();
    }

    /**
     * Hook to perform initializing
     * @return null
     */
    protected function initialize() {

    }

    /**
     * Adds a supported extension to this controller
     * @param string $extension Name of the extension
     * @return null
     */
    protected function addSupportedExtension($extension) {
        $this->supportedExtensions[$extension] = $extension;
    }

    /**
     * Request the provided extension for the current action
     * @param string $extension Name of the extension
     * @return boolean True if the extension is supported, false otherwise
     */
    private function requestExtension($extension) {
        if (!isset($this->supportedExtensions[$extension])) {
            return false;
        }

        $this->requestedExtensions[$extension] = $extension;

        return true;
    }

    /**
     * Uses the provided extension for the current action
     * @param string $extension Name of the extension
     * @return null
     */
    protected function useExtension($extension) {
        if (!isset($this->supportedExtensions[$extension])) {
            throw new JsonApiException('Could not use extension: extension ' . $extension . ' is not supported, call addSupportedExtension first');
        }

        $this->usedExtensions[$extension] = $extension;
    }

    /**
     * Checks if the provided extension is supported
     * @param string $extension Name of the extension
     * @return boolean
     */
    protected function isExtensionSupported($extension) {
        return isset($this->supportedExtensions[$extension]);
    }

    /**
     * Checks if the provided extension is supported
     * @param string $extension Name of the extension
     * @return boolean
     */
    protected function isExtensionRequested($extension) {
        return isset($this->requestedExtensions[$extension]);
    }

    /**
     * Checks if the provided extension is used
     * @param string $extension Name of the extension
     * @return boolean
     */
    protected function isExtensionUsed($extension) {
        return isset($this->usedExtensions[$extension]);
    }

    /**
     * Checks the content type before every action and creates a document
     * @return boolean True when the action is allowed, false otherwise
     */
    public function preAction() {
        // handles to content type
        $contentType = $this->request->getHeader(Header::HEADER_CONTENT_TYPE);
        if ($contentType && !$this->isValidContentType($contentType)) {
            return false;
        }

        // creates a document for the incoming request
        $query = $this->api->createQuery($this->request->getQueryParameters());
        $this->document = $this->api->createDocument($query);

        return true;
    }

    /**
     * Handles the incoming content type to comply the specification
     * @param string $contentType Value of the Content-type header
     * @return boolean True when valid, false otherwise. The appropriate status
     * code is set to the response when the content type is invalid
     */
    private function isValidContentType($contentType) {
        $mediaType = $this->mimeService->getMediaType($contentType);
        if ($mediaType->getMimeType() != JsonApi::CONTENT_TYPE) {
            return true;
        }

        $parameters = $mediaType->getParameters();
        if (isset($parameters['ext']) && $parameters['ext']) {
            $requestedExtensions = explode(',', $requestedExtensions);
            foreach ($requestedExtensions as $extension) {
                if (!$this->requestExtension($extension)) {
                    // Servers that do not support a requested extension or
                    // combination of extensions MUST return a 406 Not
                    // Acceptable status code.
                    $this->response->setStatusCode(Response::STATUS_CODE_NOT_ACCEPTABLE);

                    return false;
                }
            }

            unset($parameters['ext']);
        }

        if ($parameters) {
            // Servers MUST respond with a 415 Unsupported Media Type status
            // code if a request specifies the header Content-Type:
            // application/vnd.api+json with any media type parameters.
            $this->response->setStatusCode(Response::STATUS_CODE_UNSUPPORTED_MEDIA_TYPE);

            return false;
        }

        return true;
    }

    /**
     * Sets the response after every action based on the document
     * @return null
     */
    public function postAction() {
        $this->response->setStatusCode($this->document->getStatusCode());

        if (!$this->document->hasContent()) {
            return;
        }

        $this->setJsonView($this->document);

        $extensions = '';
        if ($this->usedExtensions) {
            $extensions .= '; ext="' . implode(',', $this->usedExtensions) . '"';
        }
        if ($this->supportedExtensions) {
            $extensions .= '; supported-ext="' . implode(',', $this->supportedExtensions) . '"';
        }

        $this->response->setHeader(Header::HEADER_CONTENT_TYPE, JsonApi::CONTENT_TYPE . $extensions);
    }

    /**
     * Creates a sorter for the provided resource type
     * @param string $resourceType Name of the resource
     * @param array $allowedFields Array with the field names to support
     * @return \ride\library\reflection\Sorter|null An instance of a sorter if
     * the request is valid, null otherwise
     */
    protected function createSorter($resourceType, array $allowedFields) {
        $sort = $this->document->getQuery()->getSort();
        foreach ($sort as $sortField => $sortDirection) {
            if (!in_array($sortField, $allowedFields)) {
                $this->addSortFieldNotFoundError($resourceType, $sortField);

                unset($sort[$sortField]);
            } elseif ($sortDirection == JsonApiQuery::SORT_ASC) {
                $sort[$sortField] = true;
            } else {
                $sort[$sortField] = false;
            }
        }

        if ($this->document->getErrors()) {
            return null;
        }

        return $this->dependencyInjector->get('ride\\library\\reflection\\Sorter', null, array('sortProperties' => $sort));
    }

    /**
     * Gets the body from the request and parses the JSON into PHP
     * @return array|boolean An array with the parsed JSON, false on failure. An
     * error is added to the document when the body could not be parsed.
     */
    protected function getBody() {
        try {
            return $this->jsonParser->parseToPhp($this->request->getBody());
        } catch (ConfigException $exception) {
            list($title, $description) = explode(':', $exception->getMessage());

            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.body', $title, ucfirst(trim($description)));

            $this->document->addError($error);

            return false;
        }
    }

    protected function addResourceNotFoundError($resourceType, $id, $source = null) {
        $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'resource.found', 'Resource does not exist');
        $error->setDetail('Resource with type \'' . $resourceType . '\' and id \'' . $id . '\' does not exist');
        if ($source) {
            $error->setSourcePointer($source);
        }

        $this->document->addError($error);
    }

    protected function addFilterNotFoundError($resourceType, $filter) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.filter', 'Filter does not exist');
        $error->setDetail('Filter \'' . $filter . '\' does not exist in resource type \'' . $resourceType . '\'');
        $error->setSourceParameter(JsonApiQuery::PARAMETER_FILTER);

        $this->document->addError($error);
    }

    protected function addSortFieldNotFoundError($resourceType, $sortField) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'index.order', 'Sort field does not exist');
        $error->setDetail('Sort field \'' . $sortField . '\' does not exist in resource type \'' . $resourceType . '\'');
        $error->setSourceParameter(JsonApiQuery::PARAMETER_SORT);

        $this->document->addError($error);
    }

    protected function addTypeNotFoundError($index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.type', 'No resource type submitted');
        $error->setDetail('No resource type found in the submitted body');
        $error->setSourcePointer('/data/' . $index . 'type');

        $this->document->addError($error);
    }

    protected function addTypeMatchError($resourceType, $jsonType, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.type.match', 'Submitted resource type does not match the URL resource type');
        $error->setDetail('Submitted resource type \'' . $jsonType . '\' does not match the URL type \'' . $resourceType . '\'');
        $error->setSourcePointer('/data/' . $index . 'type');

        $this->document->addError($error);
    }

    protected function addDataNotFoundError() {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input', 'No data attribute submitted');
        $error->setDetail('No data attribute found in the submitted body');
        $error->setSourcePointer('/data');

        $this->document->addError($error);
    }

    protected function addDataValidationError($errorDetail) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.validation', 'Data attribute is invalid');
        $error->setDetail('Value of the data attribute ' . $errorDetail);
        $error->setSourcePointer('/data');

        $this->document->addError($error);
    }

    protected function addDataExistsError($index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.exists', 'Submitted resource exists');
        $error->setDetail('Submitted resource seems to exist');
        $error->setSourcePointer('/data/' . $index);

        $this->document->addError($error);
    }

    protected function addIdNotFoundError($index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.id', 'No resource id submitted');
        $error->setDetail('No resource id found in the submitted body');
        $error->setSourcePointer('/data/' . $index . 'id');

        $this->document->addError($error);
    }

    protected function addIdInputError($id, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.id.validation', 'Could not create a resource with a client generated id');
        $error->setDetail('Client generated id \'' . $id . '\' cannot be used by the resource backend');
        $error->setSourcePointer('/data/' . $index . 'id');

        $this->document->addError($error);
    }

    protected function addIdMatchError($resourceId, $jsonId, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_CONFLICT, 'input.id.match', 'Submitted resource id does not match the URL resource id');
        $error->setDetail('Submitted resource id \'' . $jsonId . '\' does not match the URL id \'' . $resourceId . '\'');
        $error->setSourcePointer('/data/' . $index . 'id');

        $this->document->addError($error);
    }

    protected function addAttributeError($attribute, $errorCode, $errorMessage, $errorDetail, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, $errorCode, $errorMessage);
        $error->setDetail($errorDetail);
        $error->setSourcePointer('/data/' . $index . 'attributes/' . $attribute);

        $this->document->addError($error);
    }

    protected function addAttributeInputError($resourceType, $attribute, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.attribute', 'Could not set attribute');
        $error->setDetail('Attribute \'' . $attribute . '\' does not exist for type \'' . $resourceType . '\'');
        $error->setSourcePointer('/data/' . $index . 'attributes/' . $attribute);

        $this->document->addError($error);
    }

    protected function addAttributeValidationError($resourceType, $attribute, $errorDetail, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.attribute.validation', 'Attribute value is invalid');
        $error->setDetail('Value of attribute \'' . $attribute . '\' for type \'' . $resourceType . '\' ' . $errorDetail);
        $error->setSourcePointer('/data/' . $index . 'attributes/' . $attribute);

        $this->document->addError($error);
    }

    protected function addAttributeReadonlyError($resourceType, $attribute, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.attribute.readonly', 'Attribute is read-only');
        $error->setDetail('Attribute \'' . $attribute . '\' for type \'' . $resourceType . '\' cannot be set since it\'s a read-only attribute');
        $error->setSourcePointer('/data/' . $index . 'attributes/' . $attribute);

        $this->document->addError($error);
    }

    protected function addRelationshipInputError($resourceType, $relationship, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship', 'Could not set relationship');
        $error->setDetail('Relationship \'' . $relationship . '\' does not exist for type \'' . $resourceType . '\'');
        $error->setSourcePointer('/data/' . $index . 'relationships/' . $relationship);

        $this->document->addError($error);
    }

    protected function addRelationshipNotFoundError($resourceType, $relationship, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship.found', 'Relationship does not exist');
        $error->setDetail('Relationship \'' . $relationship . '\' does not exist for type \'' . $resourceType . '\'');
        $error->setSourcePointer('/data/' . $index . 'relationships/' . $relationship);

        $this->document->addError($error);
    }

    protected function addRelationshipResourceNotFoundError($resourceType, $id, $source) {
        $error = $this->api->createError(Response::STATUS_CODE_NOT_FOUND, 'input.relationship.found', 'Resource does not exist');
        $error->setDetail('Resource with type \'' . $resourceType . '\' and id \'' . $id . '\' does not exist');
        $error->setSourcePointer($source);

        $this->document->addError($error);
    }

    protected function addRelationshipDataError($relationship, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship.data', 'Invalid relationship data');
        $error->setDetail('Submitted relationship \'' . $relationship . '\' does not contain a data member');
        $error->setSourcePointer('/data/' . $index . 'relationships/' . $relationship);

        $this->document->addError($error);
    }

    protected function addRelationshipValidationError($resourceType, $relationship, $errorDetail, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship.validation', 'Relationship value is invalid');
        $error->setDetail('Value of relationship \'' . $relationship . '\' for type \'' . $resourceType . '\' ' . $errorDetail);
        $error->setSourcePointer('/data/' . $index . 'relationships/' . $relationship);

        $this->document->addError($error);
    }

    protected function addRelationshipReadonlyError($resourceType, $relationship, $index = null) {
        $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, 'input.relationship.readonly', 'Relationship is read-only');
        $error->setDetail('Relationship \'' . $attribute . '\' for type \'' . $resourceType . '\' cannot be set since it\'s a read-only relationship');
        $error->setSourcePointer('/data/' . $index . 'relationships/' . $relationship);

        $this->document->addError($error);
    }

}
