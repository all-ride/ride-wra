<?php

namespace ride\web\rest;

use ride\library\event\Event;
use ride\library\http\jsonapi\JsonApi;
use ride\library\http\Header;

use ride\web\mvc\view\JsonView;

/**
 * Application listener to override the default exception view with a Json API
 * response when needed
 */
class ApplicationListener {

    /**
     * Handle a exception, redirect to the error report form
     * @param \ride\library\event\Event $event
     * @param \ride\library\event\EventManager $eventManager
     * @param \ride\library\i18n\I18n $i18n
     * @return null
     */
    public function handleException(Event $event) {
        $web = $event->getArgument('web');
        $exception = $event->getArgument('exception');
        $request = $web->getRequest();
        $response = $web->getResponse();

        $accept = $request->getAccept();
        foreach ($accept as $mime => $priority) {
            switch ($mime) {
                case '*/*':
                case 'text/html':
                case 'application/xhtml+xml':
                    return;
                case JsonApi::CONTENT_TYPE:
                    break;
            }
        }

        $api = new JsonApi();

        $code = get_class($exception);
        $code = str_replace('Exception', '', $code);
        $code = join('', array_slice(explode('\\', $code), -1));
        $code = strtolower($code);
        $code = 'exception' . ($code ? '.' . $code : '');

        $error = $api->createError($response->getStatusCode(), $code, $exception->getMessage());

        $document = $api->createDocument();
        $document->addError($error);

        $response->setHeader(Header::HEADER_CONTENT_TYPE, JsonApi::CONTENT_TYPE);
        $response->setView(new JsonView($document, JSON_PRETTY_PRINT));
    }

}
