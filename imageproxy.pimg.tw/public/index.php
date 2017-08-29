<?php
/**
 * Source code of images.weserv.nl, to be used on your own server(s).
 *
 * PHP version 7
 *
 * @category  Images
 * @package   Imagesweserv
 * @author    Andries Louw Wolthuizen <info@andrieslouw.nl>
 * @author    Kleis Auke Wolthuizen   <info@kleisauke.nl>
 * @license   http://opensource.org/licenses/bsd-license.php New BSD License
 * @link      images.weserv.nl
 * @copyright 2017
 */

error_reporting(E_ALL);
set_time_limit(180);
ini_set('display_errors', 0);

require __DIR__ . '/../vendor/autoload.php';

use AndriesLouw\imagesweserv\Exception\ImageNotReadableException;
use AndriesLouw\imagesweserv\Exception\ImageNotValidException;
use AndriesLouw\imagesweserv\Exception\ImageTooBigException;
use AndriesLouw\imagesweserv\Exception\ImageTooLargeException;
use AndriesLouw\imagesweserv\Exception\RateExceededException;
use AndriesLouw\imagesweserv\Manipulators\Helpers\Utils;
use GuzzleHttp\Exception\RequestException;
use Jcupitt\Vips\Exception as VipsException;
use League\Uri\Components\HierarchicalPath as Path;
use League\Uri\Components\Query;
use League\Uri\Schemes\Http as HttpUri;

// See for an example: config.example.php
/** @noinspection PhpIncludeInspection */
$config = @include (__DIR__ . '/../config.php') ?: [];

$error_messages = [
    'invalid_url' => [
        'header' => '404 Not Found',
        'content-type' => 'text/plain',
        'message' => 'Error 404: Server couldn\'t parse the ?url= that you were looking for, because it isn\'t a valid url.',
    ],
    'invalid_redirect_url' => [
        'header' => '404 Not Found',
        'content-type' => 'text/plain',
        'message' => 'Error 404: Unable to parse the redirection URL.',
    ],
    'invalid_image' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'message' => 'The request image is not a valid (supported) image. Supported images are: %s',
        'log' => 'Non-supported image. URL: %s',
    ],
    'image_too_big' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'message' => 'The image is too big to be downloaded.' . PHP_EOL . 'Image size %s'
            . PHP_EOL . 'Max image size: %s',
        'log' => 'Image too big. URL: %s',
    ],
    'curl_error' => [
        'header' => '404 Not Found',
        'content-type' => 'text/html',
        'message' => 'Error 404: Server couldn\'t parse the ?url= that you were looking for, error it got: The requested URL returned error: %s',
        'log' => 'cURL Request error: %s URL: %s',
    ],
    'dns_error' => [
        'header' => '410 Gone',
        'content-type' => 'text/plain',
        'message' => 'Error 410: Server couldn\'t parse the ?url= that you were looking for, because the hostname of the origin is unresolvable (DNS) or blocked by policy.',
        'log' => 'cURL Request error: %s URL: %s',
    ],
    'rate_exceeded' => [
        'header' => '429 Too Many Requests',
        'content-type' => 'text/plain',
        'message' => 'There are an unusual number of requests coming from this IP address.',
    ],
    'image_not_readable' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'Image not readable. URL: %s Message: %s',
    ],
    'image_too_large' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'message' => 'Image is too large for processing. Width x Height should be less than 70 megapixels.',
        'log' => 'Image too large. URL: %s',
    ],
    'libvips_error' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'libvips error. URL: %s Message: %s',
    ],
    'unknown' => [
        'header' => '500 Internal Server Error',
        'content-type' => 'text/plain',
        'message' => 'Something\'s wrong!' . PHP_EOL .
            'It looks as though we\'ve broken something on our system.' . PHP_EOL .
            'Don\'t panic, we are fixing it! Please come back in a while.. ',
        'log' => 'URL: %s, Message: %s, Instance: %s',
    ]
];

/**
 * Create a new HttpUri instance from a string.
 * The string must comply with the following requirements:
 *  - Starting without 'http:' or 'https:'
 *  - HTTPS origin hosts must be prefixed with 'ssl:'
 *  - Valid according RFC3986 and RFC3987
 *
 * @param string $url
 *
 * @throws InvalidArgumentException if the URI is invalid
 * @throws League\Uri\Schemes\UriException if the URI is in an invalid state according to RFC3986
 *
 * @return HttpUri parsed URI
 */
function parseUrl(string $url)
{
    // Check for HTTPS origin hosts
    if (strpos($url, 'ssl:') === 0) {
        return HttpUri::createFromString('https://' . ltrim(substr($url, 4), '/'));
    }

    // 有夾帶http or https 直接回傳
    if (0 !== strpos($url, 'http:') || 0 !== strpos($url, 'https:')) {
        return HttpUri::createFromString(trim($url, '/'));
    }

    // Check if a valid URL is given. Therefore starting without 'http:' or 'https:'.
    if (strpos($url, 'http:') !== 0 && strpos($url, 'https:') !== 0) {
        return HttpUri::createFromString('http://' . ltrim($url, '/'));
    }

    // Not a valid URL; throw InvalidArgumentException
    throw new InvalidArgumentException('Invalid URL');
}

/**
 * Sanitize the 'errorredirect' GET variable after parsing.
 * The HttpUri instance must comply with the following requirements:
 *  - Must not include a 'errorredirect' querystring (if it does, it will be ignored)
 *
 * @param HttpUri $errorUrl
 *
 * @return string sanitized URI
 */
function sanitizeErrorRedirect(HttpUri $errorUrl)
{
    $queryStr = $errorUrl->getQuery();
    if (!empty($queryStr)) {
        $query = new Query($queryStr);
        if ($query->hasPair('errorredirect')) {
            $newQuery = $query->withoutPairs(['errorredirect']);
            return $errorUrl->withQuery($newQuery->__toString())->__toString();
        }
    }
    return $errorUrl->__toString();
}

function parseGetArgs($gets) {
    $uris = parse_url($_SERVER['REQUEST_URI']);
    if ('' === $uris['path']) {
        return $gets;
    }

    switch ($uris['path']) {
        case '/crop':
            $gets['crop'] = sprintf(
                '%s,%s,%s,%s',
                $gets['width'],
                $gets['height'],
                isset($gets['x']) ? $gets['x'] : 0,
                isset($gets['y']) ? $gets['y'] : 0
            );
            break;

        case '/resize':
            if (isset($gets['maxwidth']) or isset($gets['width'])) {
                $gets['w'] = isset($gets['maxwidth']) ? $gets['maxwidth'] : $gets['width'];
            }
            if (isset($gets['maxheight']) or isset($gets['height'])) {
                $gets['h'] = isset($gets['maxheight']) ? $gets['maxheight'] : $gets['height'];
            }
            break;

        case '/zoomcrop':
            $gets['w'] = $gets['width'];
            $gets['h'] = $gets['height'];
            $gets['t'] = isset($gets['t']) ? $gets['t'] : 'square';
            break;
    }

    return $gets;
}

if (isset($_SERVER['REQUEST_URI']) and '/health/check' == $_SERVER['REQUEST_URI']) {
    die('# imageproxy.pimg.tw');
}

if (!empty($_GET['url'])) {
    try {
        $uri = parseUrl($_GET['url']);
        // 相容舊版參數 https://wiki.pixnet.systems/index.php/Imageproxy
        $_GET = parseGetArgs($_GET);
    } catch (Exception $e) {
        $error = $error_messages['invalid_url'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);
        echo $error['message'];
        die;
    }

    // Get (potential) extension from path
    $extension = (new Path($uri->getPath()))->getExtension() ?? 'png';

    // Create a unique file (starting with 'imo_') in our shared memory
    $tmpFileName = tempnam('/dev/shm', 'imo_');

    // We need to add the extension to the temporary file for certain image types.
    // This ensures that the image is correctly recognized.
    if ($extension === 'svg' || $extension === 'ico') {
        // Rename our unique file
        rename($tmpFileName, $tmpFileName .= '.' . $extension);
    }

    $defaultClientConfig = [
        // User agent for this client
        'user_agent' => 'Mozilla/5.0 (compatible; ImageFetcher/7.0; +http://images.weserv.nl/)',
        // Float describing the number of seconds to wait while trying to connect to a server.
        // Use 0 to wait indefinitely.
        'connect_timeout' => 5,
        // Float describing the timeout of the request in seconds. Use 0 to wait indefinitely.
        'timeout' => 10,
        // Integer describing the max image size to receive (in bytes). Use 0 for no limits.
        'max_image_size' => 0,
        // Integer describing the maximum number of allowed redirects.
        'max_redirects' => 10,
        // Allowed mime types. Use empty array to allow all mime types
        'allowed_mime_types' => [
            /*'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',*/
        ]
    ];

    $clientConfig = isset($config['client']) ?
        array_merge($defaultClientConfig, $config['client']) :
        $defaultClientConfig;
    $guzzleConfig = $config['guzzle'] ?? [];

    // Create an PHP HTTP client
    $client = new AndriesLouw\imagesweserv\Client($tmpFileName, $clientConfig, $guzzleConfig);

    // If config throttler is set, IP isn't on the throttler whitelist and Memcached is installed
    if (isset($config['throttler']) && !isset($config['throttler-whitelist'][$_SERVER['REMOTE_ADDR']])) {
        $throttlingPolicy = new AndriesLouw\imagesweserv\Throttler\ThrottlingPolicy($config['throttling-policy']);

        // Defaulting to Redis
        $driver = $config['throttler']['driver'] ?? 'redis';

        if ($driver === 'memcached') {
            // Memcached throttler
            $memcached = new Memcached('mc');

            // When using persistent connections, it's important to not re-add servers.
            if (!count($memcached->getServerList())) {
                $memcached->setOptions([
                    Memcached::OPT_BINARY_PROTOCOL => true,
                    Memcached::OPT_COMPRESSION => false
                ]);

                $memcached->addServer($config['memcached']['host'], $config['memcached']['port']);
            }

            //if ($memcached->getVersion() === false) {
            //trigger_error('MemcachedException. Message: Could not establish Memcached connection', E_USER_WARNING);
            //}

            // Create an new Memcached throttler instance
            $throttler = new AndriesLouw\imagesweserv\Throttler\MemcachedThrottler($memcached, $throttlingPolicy,
                $config['throttler']);
        } elseif ($driver === 'redis') {
            $redis = new Predis\Client($config['redis']);

            // Create an new Redis throttler instance
            $throttler = new AndriesLouw\imagesweserv\Throttler\RedisThrottler($redis, $throttlingPolicy,
                $config['throttler']);
        }
    }

    // Set manipulators
    $manipulators = [
        new AndriesLouw\imagesweserv\Manipulators\Trim(),
        new AndriesLouw\imagesweserv\Manipulators\Size(71000000),
        new AndriesLouw\imagesweserv\Manipulators\Orientation(),
        new AndriesLouw\imagesweserv\Manipulators\Crop(),
        new AndriesLouw\imagesweserv\Manipulators\Letterbox(),
        new AndriesLouw\imagesweserv\Manipulators\Shape,
        new AndriesLouw\imagesweserv\Manipulators\Brightness(),
        new AndriesLouw\imagesweserv\Manipulators\Contrast(),
        new AndriesLouw\imagesweserv\Manipulators\Gamma(),
        new AndriesLouw\imagesweserv\Manipulators\Sharpen(),
        new AndriesLouw\imagesweserv\Manipulators\Filter(),
        new AndriesLouw\imagesweserv\Manipulators\Blur(),
        new AndriesLouw\imagesweserv\Manipulators\Background(),
    ];

    // Set API
    $api = new AndriesLouw\imagesweserv\Api\Api($client, $throttler ?? null, $manipulators);

    // Setup server
    $server = new AndriesLouw\imagesweserv\Server(
        $api
    );

    /*$server->setDefaults([
        'output' => 'png'
    ]);*/
    /*$server->setPresets([
        'small' => [
            'w' => 200,
            'h' => 200,
            'fit' => 'crop',
        ],
        'medium' => [
            'w' => 600,
            'h' => 400,
            'fit' => 'crop',
        ]
    ]);*/

    try {
        /**
         * Generate and output image.
         */
        $server->outputImage($uri, $_GET);
    } catch (ImageTooLargeException $e) {
        $error = $error_messages['image_too_large'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);
        echo $error['message'];
    } catch (RequestException $e) {
        $previousException = $e->getPrevious();
        $clientOptions = $client->getOptions();

        // Check if there is a previous exception
        if ($previousException instanceof ImageNotValidException) {
            $error = $error_messages['invalid_image'];
            $supportedImages = array_pop($clientOptions['allowed_mime_types']);

            if (count($clientOptions['allowed_mime_types']) > 1) {
                $supportedImages = implode(', ', $clientOptions['allowed_mime_types']) . ' and ' . $supportedImages;
            }

            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
            header('Content-type: ' . $error['content-type']);

            trigger_error(sprintf($error['log'], $uri->__toString()), E_USER_WARNING);

            echo sprintf($error['message'], $supportedImages);
        } elseif ($previousException instanceof ImageTooBigException) {
            $error = $error_messages['image_too_big'];
            $imageSize = $previousException->getMessage();
            $maxImageSize = Utils::formatSizeUnits($clientOptions['max_image_size']);

            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
            header('Content-type: ' . $error['content-type']);

            trigger_error(sprintf($error['log'], $uri->__toString()), E_USER_WARNING);

            echo sprintf($error['message'], $imageSize, $maxImageSize);
        } elseif ($previousException instanceof InvalidArgumentException) {
            $error = $error_messages['invalid_redirect_url'];
            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
            header('Content-type: ' . $error['content-type']);
            echo $error['message'];
        } else {
            $curlHandler = $e->getHandlerContext();

            $isDnsError = isset($curlHandler['errno']) && $curlHandler['errno'] === 6;

            $error = $isDnsError ? $error_messages['dns_error'] : $error_messages['curl_error'];

            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
            header('Content-type: ' . $error['content-type']);

            $statusCode = $e->getCode();
            $reasonPhrase = $e->getMessage();
            $response = $e->getResponse();

            if ($response !== null && $e->hasResponse()) {
                $statusCode = $response->getStatusCode();
                $reasonPhrase = $response->getReasonPhrase();
            }

            $errorMessage = "$statusCode $reasonPhrase";

            if (!$isDnsError && isset($_GET['errorredirect'])) {
                $isSameHost = 'weserv.nl';

                try {
                    $uri = parseUrl($_GET['errorredirect']);

                    $append = substr($uri->getHost(), -strlen($isSameHost)) === $isSameHost ? "&error=$statusCode" : '';

                    $sanitizedUri = sanitizeErrorRedirect($uri);

                    header('Location: ' . $sanitizedUri . $append);
                } catch (Exception $ignored) {
                    $message = sprintf($error['message'], $errorMessage);

                    echo $message;
                }
            } else {
                $message = $isDnsError ? $error['message'] : sprintf($error['message'], $errorMessage);

                echo $message;
            }
        }
    } catch (RateExceededException $e) {
        $error = $error_messages['rate_exceeded'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);
        echo $error['header'] . ' - ' . $error['message'];
    } catch (ImageNotReadableException $e) {
        $error = $error_messages['image_not_readable'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (VipsException $e) {
        $error = $error_messages['libvips_error'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        // Log libvips exceptions
        trigger_error(
            sprintf(
                $error['log'],
                $uri->__toString(),
                $e->getMessage()
            ),
            E_USER_WARNING
        );

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (Exception $e) {
        // If there's an exception which is not already caught.
        // Then it's a unknown exception.
        $error = $error_messages['unknown'];

        // Log unknown exceptions
        trigger_error(
            sprintf(
                $error['log'],
                $uri->__toString(),
                $e->getMessage(),
                get_class($e)
            ),
            E_USER_WARNING
        );

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);
        echo $error['message'];
    }

    // Still here? Unlink the temporary file.
    @unlink($tmpFileName);
}
