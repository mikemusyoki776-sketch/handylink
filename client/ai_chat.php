
<?php
/**
 * HandyLink Assistant
 * File: client/ai_chat.php
 *
 * Database-driven rule-based chatbot.
 * No external AI API required.
 */

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------
   JSON RESPONSE
------------------------------------------------------- */

function sendResponse($reply, $services = [], $statusCode = 200)
{
    // Prevent PHP warnings or accidental output from corrupting JSON.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);

    echo json_encode([
        'reply' => (string)$reply,
        'services' => $services
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
}

/* -------------------------------------------------------
   ERROR HANDLING
------------------------------------------------------- */

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    error_log("HandyLink chatbot PHP error: $message in $file on line $line");

    // Do not print PHP warnings into the JSON response.
    return true;
});

/* -------------------------------------------------------
   DATABASE CONNECTION
------------------------------------------------------- */

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $error) {
    error_log('HandyLink database include error: ' . $error->getMessage());

    sendResponse(
        'Sorry, the HandyLink assistant is temporarily unavailable. Please try again later.',
        [],
        500
    );
}

/* -------------------------------------------------------
   AUTHENTICATION
------------------------------------------------------- */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'client'
) {
    sendResponse(
        'Please log in as a client to use the HandyLink assistant.',
        [],
        401
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(
        'Please send your message using the chat box.',
        [],
        405
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    sendResponse(
        'Sorry, the assistant cannot access the database right now. Please try again later.',
        [],
        500
    );
}

/* -------------------------------------------------------
   READ CLIENT MESSAGE
------------------------------------------------------- */

$input = json_decode(file_get_contents('php://input'), true);

$message = '';

if (is_array($input) && isset($input['message'])) {
    $message = trim((string)$input['message']);
}

if ($message === '') {
    sendResponse('Please type a message so I can help you.');
}

if (strlen($message) > 2000) {
    sendResponse('Please keep your message to 500 characters or fewer.');
}

if (function_exists('mb_strtolower')) {
    $messageClean = mb_strtolower($message, 'UTF-8');
} else {
    $messageClean = strtolower($message);
}

$messageClean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $messageClean);
$messageClean = trim(preg_replace('/\s+/', ' ', $messageClean));

/* -------------------------------------------------------
   HELPER FUNCTIONS
------------------------------------------------------- */

function normaliseText($text)
{
    $text = (string)$text;

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

function containsAny($message, $keywords)
{
    foreach ($keywords as $keyword) {
        $keyword = normaliseText($keyword);

        if ($keyword === '') {
            continue;
        }

        $pattern = '/(?<![\p{L}\p{N}])'
            . preg_quote($keyword, '/')
            . '(?![\p{L}\p{N}])/iu';

        if (preg_match($pattern, $message)) {
            return true;
        }
    }

    return false;
}

function textContains($text, $search)
{
    if ($search === '') {
        return false;
    }

    return strpos($text, $search) !== false;
}

/* -------------------------------------------------------
   SERVICE INTENTS AND EVERYDAY EXPRESSIONS
------------------------------------------------------- */

/*
 * These expressions help the chatbot understand how clients
 * describe services in ordinary language.
 *
 * The actual services and providers are still checked in MySQL.
 */

function getServiceAliases()
{
    return [
        'barber' => [
            'barber',
            'barbering',
            'shave',
            'shaving',
            'be shaved',
            'need to be shaved',
            'i need a shave',
            'shave my beard',
            'beard trim',
            'beard trimming',
            'haircut',
            'hair cut',
            'fade',
            'trim my hair'
        ],

        'salon' => [
            'salon',
            'hair salon',
            'hairdresser',
            'hairdressing',
            'hairstylist',
            'hair styling',
            'braiding',
            'braid',
            'weaving',
            'wig installation',
            'makeup',
            'beautician',
            'beauty treatment',
            'nails',
            'manicure',
            'pedicure'
        ],

        'automotive' => [
            'mechanic',
            'car mechanic',
            'car repair',
            'vehicle repair',
            'engine repair',
            'car service',
            'automotive',
            'motorcycle repair',
            'bike repair',
            'flat tyre',
            'flat tire',
            'puncture',
            'car breakdown',
            'car broke down',
            'car will not start',
            'car wont start'
        ],

        'plumbing' => [
            'plumber',
            'plumbing',
            'leaking tap',
            'leaking pipe',
            'burst pipe',
            'blocked drain',
            'blocked sink',
            'blocked toilet',
            'water leak',
            'drainage'
        ],

        'electrical' => [
            'electrician',
            'electrical repair',
            'faulty wiring',
            'power problem',
            'short circuit',
            'electricity problem',
            'electrical installation'
        ],

        'cleaning' => [
            'house cleaner',
            'house cleaning',
            'home cleaning',
            'office cleaning',
            'deep cleaning',
            'clean my house',
            'clean my home',
            'cleaning service'
        ],

        'painting' => [
            'paint my house',
            'house painting',
            'wall painting',
            'repaint my house',
            'painting service'
        ],

        'carpentry' => [
            'carpenter',
            'woodwork',
            'furniture repair',
            'cabinet making',
            'door repair',
            'furniture making'
        ],

        'gardening' => [
            'gardener',
            'lawn mowing',
            'grass cutting',
            'landscaping',
            'garden maintenance'
        ],

        'welding' => [
            'welder',
            'metal fabrication',
            'gate welding',
            'metalwork',
            'steel fabrication'
        ],

        'masonry' => [
            'mason',
            'bricklaying',
            'plastering',
            'wall construction',
            'concrete work'
        ],

        'tiling' => [
            'tiler',
            'floor tiling',
            'wall tiling',
            'tile installation'
        ],

        'moving' => [
            'moving house',
            'house moving',
            'relocation',
            'moving furniture',
            'transport my furniture'
        ],

        'pest control' => [
            'pest control',
            'fumigation',
            'exterminate pests',
            'termites',
            'cockroaches',
            'bedbugs',
            'rats',
            'rodents'
        ],

        'photography' => [
            'photographer',
            'photography',
            'photo shoot',
            'videographer',
            'video recording'
        ],

        'laundry' => [
            'laundry',
            'washing clothes',
            'wash my clothes',
            'ironing',
            'dry cleaning'
        ],

        'security' => [
            'security guard',
            'security services',
            'private security',
            'guard services'
        ],

        'catering' => [
            'catering',
            'caterer',
            'food catering',
            'event catering',
            'cook for an event'
        ]
    ];
}

/**
 * Identify the service the client is asking about,
 * even when the service is not in the database.
 */
function detectServiceIntent($message)
{
    foreach (getServiceAliases() as $intent => $phrases) {
        if (containsAny($message, $phrases)) {
            return $intent;
        }
    }

    return null;
}

/* -------------------------------------------------------
   LOAD DATABASE SERVICE CATALOGUE
------------------------------------------------------- */

function loadServiceCatalogue($conn)
{
    $sql = "
        SELECT
            c.id AS category_id,
            c.name AS category_name,
            s.id AS service_id,
            s.service_name,
            s.description AS service_description
        FROM categories c
        LEFT JOIN services s
            ON s.category_id = c.id
            AND (s.status = 'active' OR s.status IS NULL)
        WHERE c.status = 'active'
        ORDER BY c.name ASC, s.service_name ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log('HandyLink catalogue query error: ' . $conn->error);
        return false;
    }

    $catalogue = [];

    while ($row = $result->fetch_assoc()) {
        $catalogue[] = $row;
    }

    $result->free();

    return $catalogue;
}

/* -------------------------------------------------------
   MATCH DATABASE SERVICES
------------------------------------------------------- */

function scoreServiceMatch($message, $entry, $detectedIntent)
{
    $category = normaliseText($entry['category_name'] ?? '');
    $service = normaliseText($entry['service_name'] ?? '');
    $description = normaliseText($entry['service_description'] ?? '');

    $databaseText = trim($category . ' ' . $service . ' ' . $description);

    $score = 0;

    if ($service !== '' && textContains($message, $service)) {
        $score += 15;
    }

    if ($category !== '' && textContains($message, $category)) {
        $score += 12;
    }

    /*
     * Connect recognised everyday expressions to actual
     * category names, service names and descriptions.
     */
    if ($detectedIntent !== null) {
        $aliases = getServiceAliases();

        if (isset($aliases[$detectedIntent])) {
            $intentWords = explode(' ', normaliseText($detectedIntent));

            foreach ($intentWords as $word) {
                if (
                    strlen($word) >= 3 &&
                    textContains($databaseText, $word)
                ) {
                    $score += 10;
                }
            }

            foreach ($aliases[$detectedIntent] as $phrase) {
                $phrase = normaliseText($phrase);

                if (
                    $phrase !== '' &&
                    textContains($databaseText, $phrase)
                ) {
                    $score += 15;
                    break;
                }
            }
        }
    }

    // Match meaningful words in category and service names.
    $stopWords = [
        'services', 'service', 'and', 'the',
        'for', 'with', 'from', 'your',
        'need', 'want', 'help'
    ];

    foreach ([$category, $service] as $field) {
        $words = preg_split('/\s+/', $field);

        foreach ($words as $word) {
            if (
                strlen($word) < 3 ||
                in_array($word, $stopWords, true)
            ) {
                continue;
            }

            if (containsAny($message, [$word])) {
                $score += 3;
            }
        }
    }

    return $score;
}

function matchCatalogue($message, $catalogue, $detectedIntent)
{
    $matches = [];

    foreach ($catalogue as $entry) {
        $score = scoreServiceMatch(
            $message,
            $entry,
            $detectedIntent
        );

        if ($score > 0) {
            $categoryId = (int)$entry['category_id'];

            if (
                !isset($matches[$categoryId]) ||
                $score > $matches[$categoryId]['score']
            ) {
                $matches[$categoryId] = [
                    'score' => $score,
                    'category_id' => $categoryId,
                    'category_name' => $entry['category_name']
                ];
            }
        }
    }

    usort($matches, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $matches;
}

/* -------------------------------------------------------
   FIND APPROVED PROVIDERS
------------------------------------------------------- */

function findProviders($conn, $categoryId)
{
    $sql = "
        SELECT
            s.id AS service_id,
            s.service_name,
            s.description AS service_description,
            s.price,
            s.location AS service_location,
            u.full_name,
            u.location AS provider_location,
            p.rating,
            p.availability_status
        FROM services s
        INNER JOIN providers p
            ON p.id = s.provider_id
        INNER JOIN users u
            ON u.id = p.user_id
        WHERE s.category_id = ?
          AND p.verification_status = 'approved'
          AND (u.status = 'active' OR u.status IS NULL)
          AND (s.status = 'active' OR s.status IS NULL)
        ORDER BY
            CASE
                WHEN p.availability_status = 'available' THEN 0
                ELSE 1
            END,
            p.rating DESC,
            s.created_at DESC
        LIMIT 5
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('HandyLink provider query error: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('i', $categoryId);

    if (!$stmt->execute()) {
        error_log('HandyLink provider execution error: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();

    if (!$result) {
        error_log('HandyLink provider result error: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $services = [];

    while ($row = $result->fetch_assoc()) {
        $services[] = [
            'name' => $row['service_name'],
            'url' => 'find_services.php?category=' . (int)$categoryId,
            'provider' => $row['full_name'],
            'location' => $row['service_location']
                ?: $row['provider_location'],
            'price' => $row['price'],
            'rating' => $row['rating'],
            'availability' => $row['availability_status']
        ];
    }

    $stmt->close();

    return $services;
}

/* -------------------------------------------------------
   LOAD CATALOGUE
------------------------------------------------------- */

$catalogue = loadServiceCatalogue($conn);

if ($catalogue === false) {
    sendResponse(
        'Sorry, I cannot access the HandyLink service catalogue at the moment. Please try again later.',
        [],
        500
    );
}

/* -------------------------------------------------------
   CONVERSATION CONTEXT
------------------------------------------------------- */

if (!isset($_SESSION['handylink_ai_context'])) {
    $_SESSION['handylink_ai_context'] = [];
}

$context = &$_SESSION['handylink_ai_context'];

/* -------------------------------------------------------
   START A NEW SEARCH
------------------------------------------------------- */

if (containsAny($messageClean, [
    'start over',
    'start again',
    'new search',
    'forget that',
    'different service',
    'another service'
])) {
    unset($context['category_id']);
    unset($context['category_name']);

    sendResponse(
        'No problem. What service are you looking for? You can describe what you need in your own words.'
    );
}

/* -------------------------------------------------------
   GREETINGS
------------------------------------------------------- */

if (preg_match(
    '/^(hi|hello|hey|good morning|good afternoon|good evening|howdy)\b/iu',
    $messageClean
)) {
    sendResponse(
        'Hello! Welcome to HandyLink. I can help you find services, discover approved providers, understand bookings and answer questions about using the platform. What can I help you with today?'
    );
}

if (containsAny($messageClean, [
    'thank you',
    'thanks',
    'thankyou',
    'that helps',
    'very helpful'
])) {
    sendResponse(
        'You are welcome! I am happy to help. Is there anything else you would like to know about HandyLink?'
    );
}

if (containsAny($messageClean, [
    'who are you',
    'what are you',
    'your name',
    'are you a robot',
    'are you human'
])) {
    sendResponse(
        'I am the HandyLink Assistant. I help clients understand the platform, find services and discover approved service providers.'
    );
}

/* -------------------------------------------------------
   GENERAL HANDYLINK QUESTIONS
------------------------------------------------------- */

if (containsAny($messageClean, [
    'what is handylink',
    'what does handylink do',
    'about handylink',
    'tell me about handylink',
    'how does handylink work'
])) {
    sendResponse(
        'HandyLink is a service management and provider platform that connects clients with service providers. Clients can explore services, find providers, make service requests, manage bookings and communicate about the work they need done.'
    );
}

if (containsAny($messageClean, [
    'how do i register',
    'how to register',
    'create an account',
    'sign up',
    'open an account',
    'how do i join'
])) {
    sendResponse(
        'To join HandyLink, use the registration option on the website and provide the required account details. Once registered, log in as a client to explore services and find providers.'
    );
}

if (containsAny($messageClean, [
    'forgot password',
    'reset password',
    'change my password',
    'password recovery',
    'cannot log in',
    'cant log in'
])) {
    sendResponse(
        'If you have forgotten your password, use the password recovery option on the login page if available. If you are already logged in and want to change it, check your account settings.'
    );
}

if (containsAny($messageClean, [
    'how do i book',
    'how to book',
    'make a booking',
    'book a provider',
    'request a service',
    'how do i request',
    'how to request'
])) {
    sendResponse(
        'To request a service, open Find Services, select the service you need and review the available approved providers. Open the relevant service and follow the request or booking options displayed on the page. Confirm the service details and price directly with the provider.'
    );
}

if (containsAny($messageClean, [
    'cancel booking',
    'cancel my booking',
    'cancel a booking',
    'how to cancel',
    'how do i cancel'
])) {
    sendResponse(
        'To cancel a booking, open the bookings section in your HandyLink account and check whether the booking has a cancellation option. If you cannot cancel it there, contact the provider or use the available support option.'
    );
}

if (containsAny($messageClean, [
    'track booking',
    'booking status',
    'check my booking',
    'where is my booking',
    'my request status'
])) {
    sendResponse(
        'You can check your booking or service request status from the relevant section of your HandyLink client account. The status displayed there shows the latest recorded progress.'
    );
}

if (containsAny($messageClean, [
    'leave a review',
    'write a review',
    'rate provider',
    'give a rating',
    'how to review'
])) {
    sendResponse(
        'After a service has been completed, open the relevant booking or review section in your account and use the available review option to rate and comment on the provider.'
    );
}

if (containsAny($messageClean, [
    'contact provider',
    'message provider',
    'talk to provider',
    'call provider',
    'communicate with provider'
])) {
    sendResponse(
        'You can use the contact or messaging options shown with the provider or booking, where available. Confirm the work required, location, timing and price before the service begins.'
    );
}

if (containsAny($messageClean, [
    'complaint',
    'complain',
    'fraud',
    'scam',
    'bad service',
    'poor service',
    'report a provider',
    'report provider',
    'problem with provider',
    'unsafe'
])) {
    sendResponse(
        'I am sorry you are experiencing a problem. Use the complaint or support option available in your HandyLink account, if provided. Explain what happened and include relevant booking or provider details. Do not share your password or other sensitive information.'
    );
}

if (containsAny($messageClean, [
    'payment',
    'how do i pay',
    'how to pay',
    'mpesa',
    'm pesa',
    'm p e s a',
    'service fee',
    'platform fee',
    'commission'
])) {
    sendResponse(
        'HandyLink currently allows clients to agree on payment directly with their chosen service provider. Confirm the price and payment arrangement with the provider before the work begins.'
    );
}

if (containsAny($messageClean, [
    'price',
    'how much',
    'cost',
    'charges',
    'how expensive'
])) {
    sendResponse(
        'The cost depends on the service and the work required. Some providers may list a price on their service profile. You should confirm the final cost directly with the provider before work begins.'
    );
}

if (containsAny($messageClean, [
    'become a provider',
    'register as a provider',
    'apply as a provider',
    'work for handylink',
    'join as a provider'
])) {
    sendResponse(
        'If you want to offer services through HandyLink, use the provider registration or application option on the website, if available. Provider applications are subject to the platform verification and approval process.'
    );
}

/* -------------------------------------------------------
   IDENTIFY THE SERVICE REQUEST
------------------------------------------------------- */

$detectedIntent = detectServiceIntent($messageClean);

$matches = matchCatalogue(
    $messageClean,
    $catalogue,
    $detectedIntent
);

$selectedCategory = null;

/*
 * If the client clearly asks for a known service type,
 * check whether it exists in the database.
 */
if ($detectedIntent !== null) {
    if (!empty($matches)) {
        $selectedCategory = $matches[0];
    } else {
        $friendlyName = ucfirst($detectedIntent);

        sendResponse(
            'Sorry, ' . $friendlyName
            . ' services are not currently available on HandyLink. We hope to introduce this service in the future. Is there another service I can help you find?'
        );
    }
}

/*
 * If no alias was detected, use direct database matching.
 */
if ($selectedCategory === null && !empty($matches)) {
    if ($matches[0]['score'] >= 3) {
        $selectedCategory = $matches[0];
    }
}

/* -------------------------------------------------------
   FOLLOW-UP RESPONSES
------------------------------------------------------- */

if (
    $selectedCategory === null &&
    !empty($context['category_id']) &&
    containsAny($messageClean, [
        'yes',
        'okay',
        'ok',
        'please',
        'that one',
        'the first one',
        'first',
        'sure',
        'show me',
        'go ahead'
    ])
) {
    $selectedCategory = [
        'category_id' => (int)$context['category_id'],
        'category_name' => $context['category_name']
    ];
}

/* -------------------------------------------------------
   NO SERVICE MATCH
------------------------------------------------------- */

if ($selectedCategory === null) {
    unset($context['category_id']);
    unset($context['category_name']);

    sendResponse(
        'I am not sure I understood your question. I can help with HandyLink accounts, finding providers, service requests, bookings, reviews and complaints. You can describe the work you need done in your own words. What would you like help with?'
    );
}

/* -------------------------------------------------------
   CHECK APPROVED PROVIDERS
------------------------------------------------------- */

$categoryId = (int)$selectedCategory['category_id'];
$categoryName = $selectedCategory['category_name'];

$context['category_id'] = $categoryId;
$context['category_name'] = $categoryName;

$services = findProviders($conn, $categoryId);

if ($services === false) {
    sendResponse(
        'Sorry, I could not retrieve providers at the moment. Please try again later.',
        [],
        500
    );
}

/*
 * The category exists, but no active service has an approved provider.
 */
if (empty($services)) {
    sendResponse(
        'I understand that you are looking for '
        . $categoryName
        . '. However, this service is currently unavailable because there are no active services with approved providers in this category. Please check again later as new providers may become available.'
    );
}

/* -------------------------------------------------------
   RETURN SERVICE RECOMMENDATIONS
------------------------------------------------------- */

$reply = 'I found approved providers offering '
    . $categoryName
    . '. You can open the service links below to review the available options. Check the provider location, availability and listed price, and confirm the final details directly with your chosen provider.';

sendResponse($reply, $services);

