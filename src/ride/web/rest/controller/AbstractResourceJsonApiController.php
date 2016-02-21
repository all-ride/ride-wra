<?php

namespace ride\web\rest\controller;

use ride\library\config\parser\JsonParser;
use ride\library\http\jsonapi\exception\JsonApiException;
use ride\library\http\jsonapi\JsonApiQuery;
use ride\library\http\jsonapi\JsonApi;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\log\Log;
use ride\library\reflection\ReflectionHelper;

use ride\service\MimeService;

/**
 * Abstract controller which acts for a starters implementation for a JSON API
 */
abstract class AbstractResourceJsonApiController extends AbstractJsonApiController {

    const ROUTE_INDEX = 'index';

    const ROUTE_DETAIL = 'detail';

    const ROUTE_RELATED = 'related';

    protected $type;

    protected $idField;

    protected $attributes;

    protected $relationships;

    protected $routes;

    /**
     * Constructs a new JSON API controller
     * @param \ride\library\http\jsonapi\JsonApi $api
     * @param \ride\library\log\Log $log
     * @return null
     */
    public function __construct(JsonApi $jsonApi, JsonParser $jsonParser, MimeService $mimeService, ReflectionHelper $reflectionHelper) {
        $this->reflectionHelper = $reflectionHelper;

        $this->attributes = array();
        $this->relationships = array();
        $this->routes = array();

        parent::__construct($jsonApi, $jsonParser, $mimeService);
    }

    /**
     * Sets the resource type this controller handles
     * @param string $type Name of the resource type
     * @return null
     */
    protected function setType($type) {
        $this->type = $type;
    }

    /**
     * Sets the id field in the resource data
     * @param string $idField Name of the id field in the resource data
     * @return null
     */
    protected function setIdField($idField) {
        $this->idField = $idField;
    }

    public function setAttribute($attribute) {
        $this->attributes[$attribute] = array(
            'name' => $attribute,
        );
    }

    public function setRelationship($relationship, $type, $id) {
        $this->relationships[$relationship] = array(
            'name' => $relationship,
            'type' => $type,
            'id' => $id,
        );
    }

    protected function setRoute($type, $routeId) {
        $this->routes[$type] = $routeId;
    }

    protected function getRoute($type) {
        if (!isset($this->routes[$type])) {
            throw new JsonApiException('Could not get route: ' . $type . ' not set');
        }

        return $this->routes[$type];
    }

    /**
     * Action to get a collection of resources
     * @return null
     */
    public function indexAction() {
        $query = $this->document->getQuery();

        $resources = $this->getResources($query, $total);

        if (!$this->document->getErrors()) {
            $this->document->setLink('self', $this->request->getUrl());
            $this->document->setResourceCollection($this->type, $resources);
            $this->document->setMeta('total', $total);
        }
    }

    /**
     * Action to get the details of the provided resource
     * @param string $id Id of the resource
     * @return null
     */
    public function detailAction($id) {
        $resource = $this->getResource($id);
        if (!$resource) {
            return;
        }

        $this->document->setResourceData($this->type, $resource);
        $this->document->setLink('self', $this->request->getUrl());
    }

    /**
     * Action to get a related value of the provided resource
     * @param string $id Id of the resource
     * @param string $relationship Name of the relationship
     * @return null
     */
    public function relatedAction($id, $relationship) {
        $resource = $this->getResource($id);
        if (!$resource) {
            return;
        }

        if (!isset($this->relationships[$relationship])) {
            $this->addRelationshipNotFoundError($this->type, $id, $relationship);

            return;
        }

        $value = $this->reflectionHelper->getProperty($resource, $relationship);

        $this->document->setResourceData($this->relationships[$relationship]['type'], $value);
        $this->document->setLink('self', $this->request->getUrl());
    }

    /**
     * Action to get a relationship of the provided resource
     * @param string $id Id of the resource
     * @param string $relationship Name of the relationship
     * @return null
     */
    public function relationshipAction($id, $relationship) {
        $resource = $this->getResource($id);
        if (!$resource) {
            return;
        }

        if (!isset($this->relationships[$relationship])) {
            $this->addRelationshipNotFoundError($this->type, $id, $relationship);

            return;
        }

        $relationshipProperties = $this->relationships[$relationship];

        $value = $this->reflectionHelper->getProperty($resource, $relationship);
        $id = $this->reflectionHelper->getProperty($value, $relationshipProperties['id']);

        $relationshipResource = $this->api->createResource($relationshipProperties['type'], $id);
        $relationshipData = $this->api->createRelationship();
        $relationshipData->setResource($relationshipResource);

        $this->document->setLink('self', $this->request->getUrl());
        $this->document->setLink('related', $this->getUrl($this->getRoute(self::ROUTE_RELATED), array('id' => $id, 'relationship' => $relationship)));
        $this->document->setRelationshipData($relationshipData);
    }

    /**
     * Action to save resources
     * @param string $id Id of the resource
     * @return null
     */
    public function saveAction($id = null) {
        $body = $this->getBody();

        if ($id === null && $this->request->isPatch() && !isset($body['data'][0])) {
            $this->addDataValidationError('should be an array');
        } elseif ($id === null && $this->isExtensionSupported(self::EXTENSION_BULK) && isset($body['data'][0])) {
            $this->handleBulkSave($body['data']);
        } elseif (isset($body['data'])) {
            $this->handleSave($body['data'], $id);
        } else {
            return $this->addDataNotFoundError();
        }
    }

    /**
     * Handles a single save action
     * @param array $data Submitted data
     * @param string $id Id of the resource
     * @return null
     */
    protected function handleSave(array $data, $id = null) {
        $resource = $this->getResourceFromData($data, $id);

        if ($this->document->getErrors()) {
            return;
        }

        $resourceId = $this->reflectionHelper->getProperty($resource, $this->idField);
        if ($id === null && $resourceId) {
            // single resource, post request but we have a submitted id
            $this->addIdInputError($resourceId);
        } elseif ($this->request->isPatch() && !$resourceId) {
            $this->addIdNotFoundError();
        }

        if ($this->document->getErrors()) {
            return;
        }

        $this->validateResource($resource);

        if ($this->document->getErrors()) {
            return;
        }

        $this->saveResource($resource);

        $resourceId = $this->reflectionHelper->getProperty($resource, $this->idField);

        $url = $this->getUrl($this->getRoute(self::ROUTE_DETAIL), array('id' => $resourceId));

        $this->document->setLink('self', $url);
        $this->document->setResourceData($this->type, $resource);

        if ($id === null) {
            $this->response->setHeader(Header::HEADER_LOCATION, $url);
            $this->document->setStatusCode(Response::STATUS_CODE_CREATED);
        }
    }

    /**
     * Handles a bulk save action
     * @param array $data Submitted data
     * @return null
     */
    protected function handleBulkSave(array $data) {
        $this->useExtension(self::EXTENSION_BULK);

        // retrieve and validate resources
        $resources = array();
        foreach ($data as $index => $resource) {
            $resources[] = $this->getResourceFromData($resource, null, $index);
        }

        if ($this->document->getErrors()) {
            return;
        }

        foreach ($resources as $index => $resource) {
            $index .= '/';
            $resourceId = $this->reflectionHelper->getProperty($resource, $this->idField);

            if ($this->request->isPost() && $resourceId) {
                $this->addIdInputError($resourceId);
            } elseif ($this->request->isPatch() && !$resourceId) {
                $this->addIdNotFoundError($index);
            }

            $this->validateResource($resource, $index);
        }

        if ($this->document->getErrors()) {
            return;
        }

        // perform save
        foreach ($resources as $index => $resource) {
            $this->saveResource($resources[$index]);
        }

        // update response document with the translations
        $url = $this->getUrl($this->getRoute(self::ROUTE_INDEX));

        $this->document->setLink('self', $url);
        $this->document->setResourceCollection($this->type, $resources);

        if ($this->request->isPost()) {
            $this->response->setHeader(Header::HEADER_LOCATION, $url);
            $this->document->setStatusCode(Response::STATUS_CODE_CREATED);
        }
    }

    /**
     * Action to delete resources
     * @param string $id Id of the resource
     * @return null
     */
    public function deleteAction($id = null) {
        if ($id) {
            $this->handleDelete($id);
        } elseif ($this->isExtensionSupported(self::EXTENSION_BULK)) {
            $this->handleBulkDelete();
        } else {
            $this->document->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
        }
    }

    /**
     * Handles a single delete action
     * @param string $id Id of the resource
     * @return null
     */
    protected function handleDelete($id) {
        $resource = $this->getResource($id);
        if (!$resource) {
            return;
        }

        $this->deleteResource($resource);
    }

    /**
     * Handles a bulk delete action
     * @return null
     */
    protected function handleBulkDelete() {
        $this->useExtension(self::EXTENSION_BULK);

        // validate incoming body
        $body = $this->getBody();
        if (!isset($body['data'][0])) {
            return $this->addDataValidationError('should be an array');
        }

        $resources = array();
        foreach ($body['data'] as $index => $resource) {
            $resource = $this->getResourceFromData($resource, null, $index);
            if ($resource === null) {
                continue;
            } elseif (!$this->reflectionHelper->getProperty($resource, $this->idField)) {
                $this->addIdNotFoundError($index . '/');
            } else {
                $resources[] = $resource;
            }
        }

        if ($this->document->getErrors()) {
            return;
        }

        foreach ($resources as $resource) {
            $this->deleteResource($resource);
        }
    }

    /**
     * Gets the resources for the provided query
     * @param \ride\library\http\jsonapi\JsonApiQuery $query
     * @param integer $total Total number of entries before pagination
     * @return mixed Array with resource data or false when an error occured
     */
    abstract protected function getResources(JsonApiQuery $query, &$total);

    /**
     * Gets the resource for the provided id
     * @param string $id Id of the resource
     * @param boolean $addError Set to false to skip adding the error when the
     * resource is not found
     * @return mixed Resource data if found or false when an error occured
     */
    abstract protected function getResource($id, $addError = true);

    /**
     * Creates empty resource data
     * @return mixed
     */
    protected function createResource() {
        $resource = array(
            $this->idField => null,
        );

        foreach ($this->attributes as $name => $attribute) {
            $resource[$name] = null;
        }
        foreach ($this->relationships as $name => $relationship) {
            $resource[$name] = null;
        }

        return $resource;
    }

    /**
     * Validates a resource before performing a save
     * @param mixed $resource Resource data
     * @param string $index
     * @return null
     */
    protected function validateResource($resource, $index = null) {

    }

    /**
     * Saves a resource to the data store
     * @param mixed $resource Resource data
     * @return null
     */
    protected function saveResource(&$resource) {
        $this->document->setStatusCode(Response::STATUS_CODE_NOT_IMPLEMENTED);
    }

    /**
     * Deletes a resource from the data store
     * @param mixed $resource Resource data
     * @return null
     */
    protected function deleteResource($resource) {
        $this->document->setStatusCode(Response::STATUS_CODE_NOT_IMPLEMENTED);
    }

    /**
     * Gets the relationship resource data with the provided id
     * @param string $relationship Name of the relationship
     * @param string $id Id of the relationship resource
     * @return mixed Relationship resource or null
     */
    protected function getRelationship($relationship, $id) {
        throw new JsonApiException('Could not get relationship ' . $relationship . ' with id ' . $id . ': not implemented');
    }

    /**
     * Gets a resource from of the provided data
     * @param array $data Data structure
     * @param string $id Requested id
     * @param integer $index Index of a bulk operation
     * @return mixed Resource data or null on failure
     */
    protected function getResourceFromData($data, $id = null, $index = null) {
        if ($index !== null) {
            $index .= '/';
        }

        // check the submitted type
        if (!isset($data['type'])) {
            return $this->addTypeNotFoundError($index);
        } elseif ($data['type'] != $this->type) {
            return $this->addTypeMatchError($this->type, $data['type'], $index);
        }

        $resource = null;
        if ($id) {
            // retrieve the requested resource
            $resource = $this->getResource($id);
            if (!$resource) {
                return;
            }
        } elseif ($this->isExtensionUsed(self::EXTENSION_BULK) && isset($data['id'])) {
            if (!$this->request->isPatch()) {
                return $this->addIdInputError($data['id'], $index);
            }

            // retrieve the bulk resource
            $resource = $this->getResource($data['id']);
            if (!$resource) {
                return;
            }
        } else {
            // create a new resource
            $resource = $this->createResource();
        }

        // check the id of the entry
        $resourceId = $this->reflectionHelper->getProperty($resource, $this->idField);
        if (!$resourceId && isset($data['id'])) {
            // client generated id is not allowed
            return $this->addIdInputError($data['id'], $index);
        } elseif ($resourceId && ((isset($data['id']) && $resourceId != $data['id']) || ($id !== null && $resourceId != $id))) {
            // submitted id does not match the url
            return $this->addIdMatchError($data['id'], $id, $index);
        }

        // handle submitted attributes
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $attribute => $value) {
                if (!isset($this->attributes[$attribute])) {
                    $this->addAttributeInputError($this->type, $attribute, $index);

                    continue;
                } elseif (!$this->processAttribute($resource, $attribute, $value, $index)) {
                    continue;
                }

                $this->reflectionHelper->setProperty($resource, $attribute, $value);
            }
        }

        // handle submitted relationships
        if (isset($data['relationships'])) {
            foreach ($data['relationships'] as $relationship => $value) {
                if (!isset($this->relationships[$relationship])) {
                    // invalid relationship
                    $this->addRelationshipInputError($this->type, $relationship, $index);
                } elseif (!isset($value['data'])) {
                    $this->addRelationshipDataError($relationship, $index);
                } elseif (!$this->processRelationship($resource, $relationship, $value['data'], $index)) {
                    continue;
                } else {
                    $relationshipResource = $this->getRelationshipFromData($relationship, $value['data'], '/data/' . $index . 'relationships/' . $relationship);
                    if (!$this->processRelationshipData($resource, $relationship, $relationshipResource, $index)) {
                        continue;
                    }

                    $this->reflectionHelper->setProperty($resource, $relationship, $relationshipResource);
                }
            }
        }

        return $resource;
    }

    /**
     * Gets a relationship from of the provided relationship data
     * @param string $relationship Name of the relationship
     * @param array $data Relationship data structure
     * @param string $source Source pointer of the data
     * @return mixed Resource data or false on failure
     */
    protected function getRelationshipFromData($relationship, $data, $source) {
        // check relationship
        $detail = null;
        if ($data === null) {
            $relationshipData = null;
        } elseif (!is_array($data)) {
            $code = 'input.relationship';
            $detail = var_export($data, true);
        } elseif (!isset($data['type'])) {
            $code = 'input.relationship.type';
            $detail = 'No type provided';
        } elseif ($data['type'] !== $this->relationships[$relationship]['type']) {
            $code = 'input.relationship.validation';
            $detail = 'Type should be \'' . $this->relationships[$relationship]['type'] . '\'';
        } elseif (!isset($data['id'])) {
            $code = 'input.relationship.id';
            $detail = 'No id provided';
        } else {
            $relationshipData = $this->getRelationship($relationship, $data['id']);
            if (!$relationshipData) {
                $this->addRelationshipResourceNotFoundError($this->relationships[$relationship]['type'], $id, $source);

                return false;
            }
        }

        if ($detail) {
            // invalid relationship
            $error = $this->api->createError(Response::STATUS_CODE_BAD_REQUEST, $code, 'Invalid relationship received', $detail);
            $error->setSourcePointer($source);

            $this->document->addError($error);

            return false;
        }

        return $relationshipData;
    }

    /**
     * Processes the provided attribute
     * @param mixed $resource Resource data being populated
     * @param string $attribute Name of the attribute
     * @param mixed $value Value of the attribute
     * @return boolean True when valid, false otherwise
     */
    protected function processAttribute($resource, $attribute, $value, $index = null) {
        return true;
    }

    /**
     * Processes the provided relationship
     * @param mixed $resource Resource data being populated
     * @param string $relationship Name of the relationship
     * @param mixed $value Value of the relationship
     * @return boolean True when valid, false otherwise
     */
    protected function processRelationship($resource, $relationship, $value, $index = null) {
        return true;
    }

    /**
     * Processes the provided relationship data
     * @param mixed $resource Resource data being populated
     * @param string $relationship Name of the relationship
     * @param mixed $value Relationship resource data
     * @return boolean True when valid, false otherwise
     */
    protected function processRelationshipData($resource, $relationship, $value, $index = null) {
        return true;
    }

    /**
     */
    protected function filterStringValue($filter, $value) {
        if (!is_array($filter)) {
            return strpos($value, $filter) !== false;
        }

        $result = false;
        foreach ($filter as $filterValue) {
            if (strpos($value, $filterValue) !== false) {
                $result = true;

                break;
            }
        }

        return $result;
    }


}
