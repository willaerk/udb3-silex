<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CultuurNet\UDB3\Doctrine\EventServiceCache;
use CultuurNet\UDB3\SearchAPI2\DefaultSearchService as SearchAPI2;
use DerAlex\Silex\YamlConfigServiceProvider;
use CultuurNet\UDB3\PullParsingSearchService;
use CultuurNet\UDB3\DefaultEventService;
use CultuurNet\UDB3\CallableIriGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

$app = new Application();

$app['debug'] = true;

$app->register(new YamlConfigServiceProvider(__DIR__ . '/../config.yml'));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\SessionServiceProvider());

$checkAuthenticated = function(Request $request, Application $app) {
    /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
    $session = $app['session'];

    if (!$session->get('culturefeed_user')) {
        return new Response('Access denied', 403);
    }
};

// Enable CORS.
$app->after(
    function (Request $request, Response $response, Application $app) {
        $origin = $request->headers->get('Origin');
        $origins = $app['config']['cors']['origins'];
        if (!empty($origins) && in_array($origin, $origins)) {
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $origin
            );
            $response->headers->set(
                'Access-Control-Allow-Credentials',
                'true'
            );
        }
    }
);

$app['iri_generator'] = $app->share(
    function($app) {
        return new CallableIriGenerator(function ($cdbid) use ($app) {
                /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator */
                $urlGenerator = $app['url_generator'];

                return $urlGenerator->generate(
                    'event',
                    array(
                        'cdbid' => $cdbid,
                    ),
                    $urlGenerator::ABSOLUTE_URL
                );
        });
    }
);

$app['search_api_2'] = $app->share(
    function($app) {
        $searchConfig = $app['config']['search'];
        $consumerCredentials = new \CultuurNet\Auth\ConsumerCredentials();
        $consumerCredentials->setKey($searchConfig['consumer']['key']);
        $consumerCredentials->setSecret($searchConfig['consumer']['secret']);
        return new SearchAPI2($searchConfig['base_url'], $consumerCredentials);
    }
);

$app['search_service'] = $app->share(
    function($app) {
        return new PullParsingSearchService($app['search_api_2'], $app['iri_generator']);
    }
);

$app['event_service'] = $app->share(
    function($app) {
        $service = new DefaultEventService($app['search_api_2'], $app['iri_generator']);
        return new EventServiceCache($service, $app['cache']);
    }
);

$app['current_user'] = $app->share(
    function ($app) {
        /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
        $session = $app['session'];

        $config = $app['config']['uitid'];

        /** @var \CultuurNet\Auth\User $minimalUserData */
        $minimalUserData = $session->get('culturefeed_user');

        $userCredentials = $minimalUserData->getTokenCredentials();

        $oauthClient = new CultureFeed_DefaultOAuthClient(
            $config['consumer']['key'],
            $config['consumer']['secret'],
            $userCredentials->getToken(),
            $userCredentials->getSecret()
        );
        $oauthClient->setEndpoint($config['base_url']);

        $cf = new CultureFeed($oauthClient);

        return $cf->getUser($minimalUserData->getId());
    }
);

$app['auth_service'] = $app->share(
  function ($app) {
      $uitidConfig = $app['config']['uitid'];

      return new CultuurNet\Auth\Guzzle\Service(
          $uitidConfig['base_url'],
          new \CultuurNet\Auth\ConsumerCredentials(
              $uitidConfig['consumer']['key'],
              $uitidConfig['consumer']['secret']
          )
      );
  }
);

$app['cache'] = $app->share(
    function ($app) {
        $cacheDirectory = __DIR__ . '/../cache';
        $cache = new \Doctrine\Common\Cache\FilesystemCache($cacheDirectory);

        return $cache;
    }
);

$app->get('culturefeed/oauth/connect', function (Request $request, Application $app) {
        /** @var CultuurNet\Auth\ServiceInterface $authService */
        $authService = $app['auth_service'];

        /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator */
        $urlGenerator = $app['url_generator'];

        $callback_url_params = array();

        if ($request->query->get('destination')) {
            $callback_url_params['destination'] = $request->query->get('destination');
        }

        $callback_url = $urlGenerator->generate(
            'culturefeed.oauth.authorize',
            $callback_url_params,
            $urlGenerator::ABSOLUTE_URL
        );

        $token = $authService->getRequestToken($callback_url);

        $authorizeUrl = $authService->getAuthorizeUrl($token);

        /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $session = $app['session'];
        $session->set('culturefeed_tmp_token', $token);

        return new RedirectResponse($authorizeUrl);
    });

$app->get('culturefeed/oauth/authorize', function(Request $request, Application $app) {
        /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $session = $app['session'];

        /** @var CultuurNet\Auth\ServiceInterface $authService */
        $authService = $app['auth_service'];

        /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator */
        $urlGenerator = $app['url_generator'];
        $query = $request->query;

        /** @var \CultuurNet\Auth\TokenCredentials $token */
        $token = $session->get('culturefeed_tmp_token');

        if ($query->get('oauth_token') == $token->getToken() && $query->get('oauth_verifier')) {

            $user = $authService->getAccessToken(
                $token,
                $query->get('oauth_verifier')
            );

            $session->remove('culturefeed_tmp_token');

            $session->set('culturefeed_user', $user);
        }

        if ($query->get('destination')) {
            return new RedirectResponse(
                $query->get('destination')
            );
        }
        else {
            return new RedirectResponse(
                $urlGenerator->generate('api/1.0/search')
            );
        }
    })
    ->bind('culturefeed.oauth.authorize');

$app->get('logout', function(Request $request, Application $app) {
        /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $session = $app['session'];
        $session->invalidate();

        return new Response('Logged out');
    });

$app->get(
    'search',
    function (Request $request, Application $app) {
        $q = $request->query->get('q');

        /** @var SearchAPI2 $service */
        $service = $app['search_api_2'];
        $q = new \CultuurNet\Search\Parameter\Query($q);
        $response = $service->search(array($q));

        $results = \CultuurNet\Search\SearchResult::fromXml(new SimpleXMLElement($response->getBody(true), 0, false, \CultureFeed_Cdb_Default::CDB_SCHEME_URL));

        $response = Response::create()
            ->setContent($results->getXml())
            ->setPublic()
            ->setClientTtl(60 * 1)
            ->setTtl(60 * 5);

        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }
)->before($checkAuthenticated);

$app->get(
    'api/1.0/event.jsonld',
    function(Request $request, Application $app) {
        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse('api/1.0/event.jsonld');
        $response->headers->set('Content-Type', 'application/ld+json');
        return $response;
    }
);

$app->get(
    'api/1.0/search',
    function (Request $request, Application $app) {
        $query = $request->query->get('query', '*.*');
        $limit = $request->query->get('limit', 30);
        $start = $request->query->get('start', 0);

        /** @var \CultuurNet\UDB3\SearchServiceInterface $searchService */
        $searchService = $app['search_service'];
        $results = $searchService->search($query, $limit, $start);

        $response = JsonResponse::create()
            ->setData($results)
            ->setPublic()
            ->setClientTtl(60 * 1)
            ->setTtl(60 * 5);

        $response->headers->set('Content-Type', 'application/ld+json');

        return $response;
    }
)->before($checkAuthenticated)->bind('api/1.0/search');

$app
    ->get(
        'event/{cdbid}',
        function(Request $request, Application $app, $cdbid) {
            /** @var \CultuurNet\UDB3\EventServiceInterface $service */
            $service = $app['event_service'];

            $event = $service->getEvent($cdbid);

            $response = JsonResponse::create()
                ->setData($event)
                ->setPublic()
                ->setClientTtl(60 * 30)
                ->setTtl(60 * 5);

            $response->headers->set('Content-Type', 'application/ld+json');

            return $response;
        }
    )
    ->bind('event');

$app->get('api/1.0/user', function (Request $request, Application $app) {
        /** @var CultureFeed_User $user */
        $user = $app['current_user'];

        $response = JsonResponse::create()
            ->setData($user)
            ->setPrivate();

        return $response;
    })->before($checkAuthenticated);

$app->run();
