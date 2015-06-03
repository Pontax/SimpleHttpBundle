<?php

/*
 * (c) Darrell Hamilton <darrell.noice@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace evaisse\SimpleHttpBundle\Http;


use evaisse\SimpleHttpBundle\Http\Exception\CurlTransportException;
use evaisse\SimpleHttpBundle\Http\Exception\HostNotFoundException;
use evaisse\SimpleHttpBundle\Http\Exception\SslException;
use evaisse\SimpleHttpBundle\Http\Exception\TimeoutException;
use evaisse\SimpleHttpBundle\Http\Exception\TransportException;

use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\HeaderBag;

use Zeroem\CurlBundle\Curl\Request as CurlRequest;
use Zeroem\CurlBundle\Curl\Collector\HeaderCollector;
use Zeroem\CurlBundle\Curl\Collector\ContentCollector;
use Zeroem\CurlBundle\Curl\CurlErrorException;
use Zeroem\CurlBundle\Curl\RequestGenerator;

use Zeroem\CurlBundle\Curl\MultiManager;
use Zeroem\CurlBundle\Curl\CurlEvents;
use Zeroem\CurlBundle\Curl\MultiInfoEvent;

use Zeroem\CurlBundle\HttpKernel\RemoteHttpKernel;


/**
 * RemoteHttpKernel utilizes curl to convert a Request object into a Response
 *
 * @author Darrell Hamilton <darrell.noice@gmail.com>
 */
class Kernel extends RemoteHttpKernel
{

    use ContainerAwareTrait;


    /**
     * An instance of Curl\RequestGenerator for getting preconfigured
     * Curl\Request objects
     *
     * @var RequestGenerator
     */
    protected $generator;

    /**
     * [$lastCurlRequest description]
     * @var resource curlRequest
     */
    protected $lastCurlRequest;


    /**
     * [$eventDispatcher description]
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $eventDispatcher;



    /**
     * [$requests description]
     * @var [type]
     */
    protected $stmts;


    public function __construct(ContainerInterface $container, RequestGenerator $generator = null) 
    {
        $this->setContainer($container);
        $this->generator = $generator;
        $this->setEventDispatcher(new \Symfony\Component\EventDispatcher\EventDispatcher());

        if ($this->container->has('simple_http.profiler.data_collector')) {
            $this->getEventDispatcher()->addSubscriber($this->container->get('simple_http.profiler.data_collector'));
        }
    }

    /**
     * @param MultiInfoEvent $e
     */
    public function handleMultiInfoEvent(MultiInfoEvent $e)
    {
        $r = $e->getRequest();

        foreach ($this->services as $key => $value) {
            if ($value[1][0] === $r) {
                break;
            }
        }

        $requestType = HttpKernelInterface::SUB_REQUEST;

        $stmt = $value[0];
        $request = $stmt->getRequest();

        list($curlRequest, $contentCollector, $headersCollector) = $value[1];

        $this->updateRequestHeadersFromCurlInfos($request, $e->getRequest()->getInfo());

        if (!$headersCollector->getCode()) {

            /*
             * Here we need to use return code from multi event because curl_errno return invalid results
             */
            $error = new CurlTransportException(curl_error($curlRequest->getHandle()),
                                                $e->getInfo()->getResult());

            $error = $error->transformToGenericTransportException();

            $stmt->setError($error);

            $event = new Event\GetResponseForExceptionEvent(
                    $this,
                    $request,
                    $requestType,
                    $error);

            $this->getEventDispatcher()->dispatch(KernelEvents::EXCEPTION, $event);

        } else {

            $response = new Response(
                $contentCollector->retrieve(),
                $headersCollector->getCode(),
                $headersCollector->retrieve()
            );

            foreach ($headersCollector->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }

            $response->setProtocolVersion($headersCollector->getVersion());
            $response->setStatusCode($headersCollector->getCode(), $headersCollector->getMessage());

            $response->setTransferInfos($e->getRequest()->getInfo());

            $event = new Event\FilterResponseEvent($this, $request, $requestType, $response);
            $this->getEventDispatcher()->dispatch(KernelEvents::RESPONSE, $event);

            /*
                populate response for service
             */
            $stmt->setResponse($response);

            $event = new Event\PostResponseEvent($this, $request, $response);
            $this->getEventDispatcher()->dispatch(KernelEvents::TERMINATE);

        }

    }

    /**
     * handle multi curl
     * @param array $stmts a list of Service instances
     * @return HttpKernel current httpkernel for method chaining
     */
    public function execute(array $stmts)
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            CurlEvents::MULTI_INFO,
            array($this,"handleMultiInfoEvent")
        );

        $mm = new MultiManager($dispatcher, false);

        $this->services = array();

        foreach ($stmts as $stmt) {

            $request = $stmt->getRequest();
            $requestType = HttpKernelInterface::SUB_REQUEST;

            try {

                $event = new Event\GetResponseEvent($this, $request, $requestType);
                $this->getEventDispatcher()->dispatch(KernelEvents::REQUEST, $event);

                list($curlHandler, $contentCollector, $headerCollector) = $this->prepareRawCurlHandler($stmt, $requestType, false);

                $mm->addRequest($curlHandler);

                $this->services[] = [
                    $stmt,
                    [$curlHandler, $contentCollector, $headerCollector],
                ];

            } catch (CurlErrorException $e) {
                $stmt->setError(new Exception\TransportException("CURL connection error", 1, $e));
                $event = new Event\GetResponseForExceptionEvent(
                    $this, $request, 
                    $requestType,
                    $stmt->getError());
                $this->getEventDispatcher()->dispatch(KernelEvents::EXCEPTION, $event);
                continue;
            }

        }


        $mm->execute();
        
        // for the "non blocking" multi manager, we need to trigger the destructor
        unset($mm);


        return $this;

    }

    /**
     * Handles a Request to convert it to a Response.
     *
     * When $catch is true, the implementation must catch all exceptions
     * and do its best to convert them to a Response instance.
     *
     * @param  HttpRequest $request A Request instance
     * @param  integer     $type    The type of the request
     *                              (one of HttpKernelInterface::MASTER_REQUEST or HttpKernelInterface::SUB_REQUEST)
     * @param  Boolean     $catch   Whether to catch exceptions or not
     *
     * @return Response A Response instance
     *
     * @throws \Exception When an Exception occurs during processing
     */
    public function handle(HttpRequest $request, $type = HttpKernelInterface::SUB_REQUEST, $catch = true) 
    {
        try {
            return $this->handleRaw($request);
        } catch (\Exception $e) {
            if (false === $catch) {
                throw $e;
            }
            return $this->handleException($e, $request);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function handleRaw(\Symfony\Component\HttpFoundation\Request $request)
    {
        return parent::handleRaw($request);
    }
    
    /**
     * 
     * @param  \Exception  $e       [description]
     * @param  HttpRequest $request [description]
     * @return Response http response 
     */
    protected function handleException(\Exception $e, HttpRequest $request) 
    {
        return new Response(
            $e->getMessage(),
            500
        );
    }


    /**
     * Get generated curl request
     * @return CurlRequest generated curl request
     */
    protected function getCurlRequest() 
    {
        if (isset($this->generator)) {
            return $this->generator->getRequest();
        } else {
            return new CurlRequest();
        }
    }

    /**
     * Execute a Request object via cURL
     *
     * @param Statement $stmt the request to execute
     *
     * @return Response
     *
     * @throws CurlErrorException 
     */
    protected function prepareRawCurlHandler(Statement $stmt)
    {
        $request = $stmt->getRequest();

        $curl = $this->lastCurlRequest = $this->getCurlRequest();

        $curl->setOptionArray(array(
            CURLOPT_URL         => $request->getUri(),
            CURLOPT_COOKIE      => $this->buildCookieString($request->cookies),
            CURLINFO_HEADER_OUT => true,
        ));


        // Set timeout
        if ($stmt->getTimeout() !== null) {
            $curl->setOption(CURLOPT_TIMEOUT_MS, $stmt->getTimeout());
        }

        if ($stmt->getIgnoreSslErrors()) {
            $curl->setOptionArray([
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
        }


        if ("PUT" === $request->getMethod() && count($request->files->all()) > 0) {
            /*
             * When only files are sent, here we can set the CURLOPT_PUT option
             */
            $curl->setMethod($request->getMethod());

            $file = current($request->files->all());

            $curl->setOptionArray(array(
                CURLOPT_INFILE     => fopen($file->getRealPath(), 'r'),
                CURLOPT_INFILESIZE => $file->getSize(),
            ));

        } else if ($request->getMethod() != "GET") {
            /*
             * When body is sent as a raw string, we need to use customrequest option
             */
            $curl->setOption(CURLOPT_CUSTOMREQUEST, $request->getMethod());
            $this->setPostFields($curl, $request);

        } else {
            /*
             * Classic case
             */
            $curl->setMethod($request->getMethod());

        }

        $content = new ContentCollector();
        $headers = new CurlHeaderCollector();

        // These options must not be tampered with to ensure proper functionality
        $curl->setOptionArray(
            array(
                CURLOPT_HTTPHEADER     => $this->buildHeadersArray($request->headers),
                CURLOPT_HEADERFUNCTION => array($headers, "collect"),
                CURLOPT_WRITEFUNCTION  => array($content, "collect"),
            )
        );

        return array(
            $curl, $content, $headers
        );
    }


    /**
     * Populate the POSTFIELDS option
     *
     * @param CurlRequest $curl cURL request object
     * @param Request $request the Request object we're populating
     */
    protected function setPostFields(CurlRequest $curl, HttpRequest $request)
    {
        $postfields = null;
        $content = $request->getContent();


        if (!empty($content)) {
            $postfields = $content;
        } else if (count($request->request->all()) > 0) {
            $postfields = http_build_query($request->request->all());
        }

        if (is_string($postfields)) {
            $curl->setOption(CURLOPT_POSTFIELDS, $postfields);
            $request->headers->set('content-length', strlen($postfields));
        }
    }

    /**
     * @param ParameterBag $cookiesBag
     *
     * @return string
     */
    protected function buildCookieString(ParameterBag $cookiesBag)
    {
        $cookies = [];

        foreach ($cookiesBag as $key => $value) {
            $cookies[] = "$key=$value";
        }

        return join(';', $cookies);
    }


    /**
     * Some headers like user-agent can be overrided by curl so we need to re-fetch postward the headers sent
     * to reset them in the original request object
     *
     * @param Request $request request object after being sent
     * @param array $curlInfo curl info needed to update the request object with final curl headers sent
     */
    protected function updateRequestHeadersFromCurlInfos(Request $request, array $curlInfo)
    {
        if (!isset($curlInfo['request_header'])) {
            return;
        }
        $headers = explode("\r\n", $curlInfo['request_header']);
        array_shift($headers);
        $replacementsHeaders = array();
        foreach ($headers as $header) {
            if (strpos($header, ':')) {
                list($k, $v) = explode(':', $header, 2);
                $v = trim($v);
                $k = trim($k);
                $replacementsHeaders[$k] = $v;
            }
        }
        $request->headers->replace($replacementsHeaders);
    }

    /**
     * Convert a HeaderBag into an array of headers appropriate for cURL
     *
     * @param HeaderBag $headerBag headers to parse
     *
     * @return array An array of header strings
     */
    protected function buildHeadersArray(HeaderBag $headerBag) 
    {
        return explode("\r\n", $headerBag);
    }

    public function getLastCurlRequest() {
        return $this->lastCurlRequest;
    }

    /**
     * Gets the [$eventDispatcher description].
     *
     * @return \Symfony\Component\EventDispatcher\EventDispatcher
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * Sets the [$eventDispatcher description].
     *
     * @param \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher the event dispatcher
     *
     * @return self
     */
    protected function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }
}