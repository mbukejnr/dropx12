<?php
/*********************************
 * EXACT LOCATION API - LILONGWE DELIVERY
 * Complete backend for Flutter address service
 *********************************/

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Id, X-Session-Token");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION & AUTH CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: 'YOUR_GOOGLE_MAPS_API_KEY');

/*********************************
 * AUTHENTICATION
 *********************************/
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function authenticateUser() {
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    return null;
}

function getUserContact($conn, $userId) {
    $stmt = $conn->prepare("SELECT full_name, phone FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * GOOGLE MAPS HELPER FUNCTIONS
 *********************************/
function autocompletePlaces($input, $sessionToken = null) {
    $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json?input=" . urlencode($input) . 
           "&components=country:mw&types=address|geocode&key=" . GOOGLE_MAPS_API_KEY;
    
    if ($sessionToken) {
        $url .= "&sessiontoken={$sessionToken}";
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function getPlaceDetails($placeId) {
    $url = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$placeId}&fields=formatted_address,name,geometry,address_components&key=" . GOOGLE_MAPS_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function getCoordinatesFromGoogle($locationName, $plotHouse) {
    $address = "Plot $plotHouse, $locationName, Lilongwe, Malawi";
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . GOOGLE_MAPS_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK' && isset($data['results'][0])) {
        $loc = $data['results'][0]['geometry']['location'];
        return [
            'latitude' => $loc['lat'],
            'longitude' => $loc['lng'],
            'place_id' => $data['results'][0]['place_id'],
            'formatted_address' => $data['results'][0]['formatted_address']
        ];
    }
    
    return null;
}

function formatFullAddress($plotHouse, $area, $sector = null, $street = null, $landmark = null) {
    $parts = [];
    if ($plotHouse) $parts[] = "Plot $plotHouse";
    if ($street) $parts[] = $street;
    if ($sector) $parts[] = $sector;
    if ($area) $parts[] = $area;
    
    $address = implode(', ', $parts);
    if ($landmark) {
        $address .= " (Near $landmark)";
    }
    $address .= ", Lilongwe";
    return $address;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = str_replace('/api/addresses.php', '', $path);
    
    $locationId = null;
    if (preg_match('/\/(\d+)$/', $path, $matches)) {
        $locationId = $matches[1];
    }
    
    // GET requests
    if ($method === 'GET') {
        if (isset($_GET['get_locations'])) {
            getLilongweLocations($conn);
        } elseif (isset($_GET['hierarchical'])) {
            getHierarchicalLocations($conn, $_GET);
        } elseif (isset($_GET['search'])) {
            searchLocations($conn, $_GET);
        } elseif (isset($_GET['autocomplete'])) {
            handleAutocomplete($conn);
        } elseif ($locationId) {
            getAddressDetails($conn, $locationId);
        } else {
            getUserAddresses($conn);
        }
    }
    // POST requests
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';
        
        if ($action === 'reverse_geocode') {
            handleReverseGeocode($conn, $input);
        } elseif ($action === 'geocode_location') {
            handleGeocodeLocation($conn, $input);
        } elseif ($action === 'validate') {
            handleValidateLocation($conn, $input);
        } elseif ($action === 'place_details') {
            handlePlaceDetails($conn, $input);
        } elseif (strpos($path, '/full') !== false) {
            createFullAddress($conn, $input);
        } else {
            createAddress($conn, $input);
        }
    }
    // PUT requests
    elseif ($method === 'PUT' && $locationId) {
        updateAddress($conn, $locationId);
    }
    // PATCH requests
    elseif ($method === 'PATCH' && $locationId) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['action']) && $input['action'] === 'set_default') {
            setDefaultAddress($conn, $locationId);
        } else {
            ResponseHandler::error('Invalid action', 400);
        }
    }
    // DELETE requests
    elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $addressId = $input['address_id'] ?? $_GET['address_id'] ?? null;
        if ($addressId) {
            deleteAddress($conn, $addressId);
        } else {
            ResponseHandler::error('Address ID required', 400);
        }
    }
    else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * HIERARCHICAL LOCATIONS (NEW)
 *********************************/
function getHierarchicalLocations($conn, $params) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $type = $params['type'] ?? null;
    $parentId = $params['parent_id'] ?? null;
    
    $sql = "SELECT id, name, display_name, sector, street_name, type, parent_id, latitude, longitude 
            FROM lilongwe_locations 
            WHERE is_active = 1";
    $params = [];
    
    if ($type) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    if ($parentId) {
        $sql .= " AND parent_id = ?";
        $params[] = $parentId;
    }
    
    $sql .= " ORDER BY sort_order ASC, display_name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'locations' => $locations,
        'total' => count($locations)
    ]);
}

/*********************************
 * SEARCH LOCATIONS (NEW)
 *********************************/
function searchLocations($conn, $params) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $query = $params['q'] ?? '';
    
    if (strlen($query) < 2) {
        ResponseHandler::success(['locations' => [], 'total' => 0]);
        return;
    }
    
    $searchTerm = "%$query%";
    
    $stmt = $conn->prepare("
        SELECT id, name, display_name, sector, street_name, type, parent_id, latitude, longitude
        FROM lilongwe_locations 
        WHERE is_active = 1 
        AND (name LIKE ? OR display_name LIKE ? OR sector LIKE ? OR street_name LIKE ?)
        ORDER BY 
            CASE 
                WHEN name LIKE ? THEN 1
                WHEN display_name LIKE ? THEN 2
                WHEN sector LIKE ? THEN 3
                WHEN street_name LIKE ? THEN 4
                ELSE 5
            END,
            display_name ASC
        LIMIT 20
    ");
    
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'locations' => $locations,
        'total' => count($locations)
    ]);
}

/*********************************
 * GET LILONGWE LOCATIONS (UPDATED)
 *********************************/
function getLilongweLocations($conn) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $stmt = $conn->prepare("
        SELECT id, name, display_name, sector, street_name, type, latitude, longitude 
        FROM lilongwe_locations 
        WHERE is_active = 1 
        ORDER BY type ASC, sort_order ASC, display_name ASC
    ");
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'locations' => $locations,
        'total' => count($locations)
    ]);
}

/*********************************
 * AUTOCOMPLETE (Google Places)
 *********************************/
function handleAutocomplete($conn) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = $_GET['input'] ?? '';
    $sessionToken = $_GET['session_token'] ?? null;
    
    if (strlen($input) < 2) {
        ResponseHandler::success(['suggestions' => [], 'total' => 0]);
        return;
    }
    
    // Search local database first
    $searchTerm = "%$input%";
    $stmt = $conn->prepare("
        SELECT id, name, display_name, sector, street_name, type, latitude, longitude, NULL as place_id
        FROM lilongwe_locations 
        WHERE is_active = 1 
        AND (name LIKE ? OR display_name LIKE ? OR sector LIKE ? OR street_name LIKE ?)
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $localResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Google Places suggestions
    $googleResults = autocompletePlaces($input, $sessionToken);
    
    $suggestions = [];
    
    // Add local results
    foreach ($localResults as $loc) {
        $suggestions[] = [
            'type' => 'local',
            'id' => $loc['id'],
            'name' => $loc['display_name'],
            'description' => $loc['type'],
            'sector' => $loc['sector'],
            'street_name' => $loc['street_name'],
            'latitude' => $loc['latitude'],
            'longitude' => $loc['longitude'],
            'place_id' => null
        ];
    }
    
    // Add Google results
    if ($googleResults && $googleResults['status'] == 'OK') {
        foreach ($googleResults['predictions'] as $prediction) {
            $suggestions[] = [
                'type' => 'google',
                'id' => null,
                'name' => $prediction['description'],
                'description' => 'Google Places',
                'sector' => null,
                'street_name' => null,
                'latitude' => null,
                'longitude' => null,
                'place_id' => $prediction['place_id']
            ];
        }
    }
    
    ResponseHandler::success([
        'suggestions' => $suggestions,
        'total' => count($suggestions)
    ]);
}

/*********************************
 * PLACE DETAILS (Google Places)
 *********************************/
function handlePlaceDetails($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $placeId = $input['place_id'] ?? '';
    
    if (!$placeId) {
        ResponseHandler::error('Place ID required', 400);
    }
    
    $details = getPlaceDetails($placeId);
    
    if ($details && $details['status'] == 'OK') {
        $result = $details['result'];
        $location = $result['geometry']['location'];
        
        ResponseHandler::success([
            'place_id' => $placeId,
            'name' => $result['name'] ?? $result['formatted_address'],
            'formatted_address' => $result['formatted_address'],
            'latitude' => $location['lat'],
            'longitude' => $location['lng'],
            'address_components' => $result['address_components'] ?? []
        ]);
    } else {
        ResponseHandler::error('Could not get place details', 404);
    }
}

/*********************************
 * CREATE FULL ADDRESS (NEW)
 *********************************/
function createFullAddress($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    if (empty($input['plot_house'])) {
        ResponseHandler::error('Plot/House number is required', 400);
    }
    
    $plotHouse = trim($input['plot_house']);
    $area = !empty($input['area']) ? trim($input['area']) : null;
    $sector = !empty($input['sector']) ? trim($input['sector']) : null;
    $street = !empty($input['street']) ? trim($input['street']) : null;
    $landmark = !empty($input['landmark']) ? trim($input['landmark']) : null;
    $label = !empty($input['label']) ? trim($input['label']) : 'Home';
    $isDefault = !empty($input['is_default']) ? 1 : 0;
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $placeId = $input['place_id'] ?? null;
    
    // Build location name for geocoding
    $locationParts = [];
    if ($area) $locationParts[] = $area;
    if ($sector) $locationParts[] = $sector;
    if ($street) $locationParts[] = $street;
    $locationName = implode(', ', $locationParts);
    
    // Get coordinates if not provided
    if (!$latitude || !$longitude) {
        $coords = getCoordinatesFromGoogle($locationName, $plotHouse);
        if ($coords) {
            $latitude = $coords['latitude'];
            $longitude = $coords['longitude'];
            $placeId = $coords['place_id'];
        }
    }
    
    if (!$latitude || !$longitude) {
        ResponseHandler::error('Could not locate this address. Please check the location.', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        if ($isDefault) {
            $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO addresses (
                user_id, label, location_name, plot_house, sector, street, landmark, 
                latitude, longitude, place_id, is_default
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $label,
            $locationName,
            $plotHouse,
            $sector,
            $street,
            $landmark,
            $latitude,
            $longitude,
            $placeId,
            $isDefault
        ]);
        
        $addressId = $conn->lastInsertId();
        
        // Ensure at least one default address exists
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1");
        $checkStmt->execute([$userId]);
        if ($checkStmt->fetchColumn() == 0) {
            $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ?");
            $stmt->execute([$addressId]);
            $isDefault = 1;
        }
        
        $conn->commit();
        
        $user = getUserContact($conn, $userId);
        $fullAddress = formatFullAddress($plotHouse, $area, $sector, $street, $landmark);
        
        ResponseHandler::success([
            'address_id' => $addressId,
            'full_address' => $fullAddress,
            'location' => [
                'area' => $area,
                'sector' => $sector,
                'street' => $street,
                'plot_house' => $plotHouse,
                'landmark' => $landmark
            ],
            'coordinates' => [
                'latitude' => $latitude,
                'longitude' => $longitude
            ],
            'map_link' => "https://www.google.com/maps?q={$latitude},{$longitude}",
            'contact' => [
                'name' => $user['full_name'],
                'phone' => $user['phone']
            ],
            'whatsapp_link' => "https://wa.me/265" . preg_replace('/[^0-9]/', '', $user['phone']),
            'is_default' => $isDefault == 1,
            'place_id' => $placeId
        ], 'Address saved successfully', 201);
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to save address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ADDRESS (Original - Kept for backward compatibility)
 *********************************/
function createAddress($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    if (empty($input['location_name']) || empty($input['plot_house'])) {
        ResponseHandler::error('Location name and plot/house number are required', 400);
    }
    
    $locationName = trim($input['location_name']);
    $plotHouse = trim($input['plot_house']);
    $landmark = !empty($input['landmark']) ? trim($input['landmark']) : null;
    $label = !empty($input['label']) ? trim($input['label']) : 'Home';
    $isDefault = !empty($input['is_default']) ? 1 : 0;
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $placeId = $input['place_id'] ?? null;
    
    // Get coordinates if not provided
    if (!$latitude || !$longitude) {
        $coords = getCoordinatesFromGoogle($locationName, $plotHouse);
        if ($coords) {
            $latitude = $coords['latitude'];
            $longitude = $coords['longitude'];
            $placeId = $coords['place_id'];
        }
    }
    
    if (!$latitude || !$longitude) {
        ResponseHandler::error('Could not locate this address. Please check the location name.', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        if ($isDefault) {
            $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO addresses (user_id, label, location_name, plot_house, landmark, latitude, longitude, place_id, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $label,
            $locationName,
            $plotHouse,
            $landmark,
            $latitude,
            $longitude,
            $placeId,
            $isDefault
        ]);
        
        $addressId = $conn->lastInsertId();
        
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = ? AND is_default = 1");
        $checkStmt->execute([$userId]);
        if ($checkStmt->fetchColumn() == 0) {
            $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ?");
            $stmt->execute([$addressId]);
            $isDefault = 1;
        }
        
        $conn->commit();
        
        $user = getUserContact($conn, $userId);
        $fullAddress = formatFullAddress($plotHouse, $locationName, null, null, $landmark);
        
        ResponseHandler::success([
            'address_id' => $addressId,
            'full_address' => $fullAddress,
            'location' => [
                'name' => $locationName,
                'plot_house' => $plotHouse,
                'landmark' => $landmark
            ],
            'coordinates' => [
                'latitude' => $latitude,
                'longitude' => $longitude
            ],
            'map_link' => "https://www.google.com/maps?q={$latitude},{$longitude}",
            'contact' => [
                'name' => $user['full_name'],
                'phone' => $user['phone']
            ],
            'whatsapp_link' => "https://wa.me/265" . preg_replace('/[^0-9]/', '', $user['phone']),
            'is_default' => $isDefault == 1,
            'place_id' => $placeId
        ], 'Address saved successfully', 201);
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to save address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET USER ADDRESSES (UPDATED)
 *********************************/
function getUserAddresses($conn) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $stmt = $conn->prepare("
        SELECT id, label, location_name, plot_house, sector, street, landmark, 
               latitude, longitude, place_id, is_default, created_at
        FROM addresses 
        WHERE user_id = ? 
        ORDER BY is_default DESC, created_at DESC
    ");
    $stmt->execute([$userId]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $user = getUserContact($conn, $userId);
    
    ResponseHandler::success([
        'addresses' => $addresses,
        'contact' => $user
    ]);
}

/*********************************
 * GET ADDRESS DETAILS (UPDATED)
 *********************************/
function getAddressDetails($conn, $addressId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $stmt = $conn->prepare("
        SELECT id, label, location_name, plot_house, sector, street, landmark, 
               latitude, longitude, place_id, is_default, created_at
        FROM addresses 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$addressId, $userId]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$address) {
        ResponseHandler::error('Address not found', 404);
    }
    
    $address['full_address'] = formatFullAddress(
        $address['plot_house'],
        $address['location_name'],
        $address['sector'],
        $address['street'],
        $address['landmark']
    );
    $address['map_link'] = "https://www.google.com/maps?q={$address['latitude']},{$address['longitude']}";
    
    ResponseHandler::success(['address' => $address]);
}

/*********************************
 * REVERSE GEOCODE
 *********************************/
function handleReverseGeocode($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $lat = $input['latitude'] ?? null;
    $lng = $input['longitude'] ?? null;
    
    if (!$lat || !$lng) {
        ResponseHandler::error('Latitude and longitude required', 400);
    }
    
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key=" . GOOGLE_MAPS_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK' && isset($data['results'][0])) {
        $address = $data['results'][0]['formatted_address'];
        ResponseHandler::success([
            'location_name' => $address,
            'latitude' => $lat,
            'longitude' => $lng,
            'place_id' => $data['results'][0]['place_id'],
            'source' => 'google'
        ]);
        return;
    }
    
    ResponseHandler::error('Could not find address for these coordinates', 404);
}

/*********************************
 * GEOCODE LOCATION
 *********************************/
function handleGeocodeLocation($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $locationName = $input['location_name'] ?? '';
    $plotHouse = $input['plot_house'] ?? '1';
    
    if (!$locationName) {
        ResponseHandler::error('Location name required', 400);
    }
    
    $address = "Plot $plotHouse, $locationName, Lilongwe, Malawi";
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . GOOGLE_MAPS_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK' && isset($data['results'][0])) {
        $location = $data['results'][0]['geometry']['location'];
        ResponseHandler::success([
            'latitude' => $location['lat'],
            'longitude' => $location['lng'],
            'place_id' => $data['results'][0]['place_id'],
            'formatted_address' => $data['results'][0]['formatted_address'],
            'source' => 'google'
        ]);
        return;
    }
    
    ResponseHandler::error('Could not find coordinates for this location', 404);
}

/*********************************
 * VALIDATE LOCATION
 *********************************/
function handleValidateLocation($conn, $input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $locationName = $input['location_name'] ?? '';
    $plotHouse = $input['plot_house'] ?? '1';
    
    if (!$locationName) {
        ResponseHandler::error('Location name required', 400);
    }
    
    $address = "Plot $plotHouse, $locationName, Lilongwe, Malawi";
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . GOOGLE_MAPS_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($data['status'] == 'OK' && isset($data['results'][0])) {
        $location = $data['results'][0]['geometry']['location'];
        ResponseHandler::success([
            'valid' => true,
            'location_name' => $locationName,
            'formatted_address' => $data['results'][0]['formatted_address'],
            'coordinates' => [
                'latitude' => $location['lat'],
                'longitude' => $location['lng']
            ],
            'place_id' => $data['results'][0]['place_id'],
            'source' => 'google'
        ]);
        return;
    }
    
    ResponseHandler::success([
        'valid' => false,
        'message' => 'Location not found. Please check the spelling or choose from suggestions.'
    ]);
}

/*********************************
 * UPDATE ADDRESS
 *********************************/
function updateAddress($conn, $addressId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $checkStmt = $conn->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
    $checkStmt->execute([$addressId, $userId]);
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Address not found', 404);
    }
    
    $updates = [];
    $params = [':id' => $addressId, ':user_id' => $userId];
    
    if (isset($input['label'])) {
        $updates[] = "label = :label";
        $params[':label'] = $input['label'];
    }
    if (isset($input['location_name'])) {
        $updates[] = "location_name = :location_name";
        $params[':location_name'] = $input['location_name'];
    }
    if (isset($input['plot_house'])) {
        $updates[] = "plot_house = :plot_house";
        $params[':plot_house'] = $input['plot_house'];
    }
    if (isset($input['sector'])) {
        $updates[] = "sector = :sector";
        $params[':sector'] = $input['sector'];
    }
    if (isset($input['street'])) {
        $updates[] = "street = :street";
        $params[':street'] = $input['street'];
    }
    if (isset($input['landmark'])) {
        $updates[] = "landmark = :landmark";
        $params[':landmark'] = $input['landmark'];
    }
    if (isset($input['is_default'])) {
        if ($input['is_default']) {
            $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        }
        $updates[] = "is_default = :is_default";
        $params[':is_default'] = $input['is_default'] ? 1 : 0;
    }
    
    if (empty($updates)) {
        ResponseHandler::error('No fields to update', 400);
    }
    
    $updates[] = "updated_at = NOW()";
    $sql = "UPDATE addresses SET " . implode(", ", $updates) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    ResponseHandler::success([], 'Address updated successfully');
}

/*********************************
 * DELETE ADDRESS
 *********************************/
function deleteAddress($conn, $addressId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $checkStmt = $conn->prepare("SELECT is_default FROM addresses WHERE id = ? AND user_id = ?");
    $checkStmt->execute([$addressId, $userId]);
    $address = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$address) {
        ResponseHandler::error('Address not found', 404);
    }
    
    $wasDefault = $address['is_default'];
    
    $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->execute([$addressId, $userId]);
    
    if ($wasDefault) {
        $newDefaultStmt = $conn->prepare("
            SELECT id FROM addresses WHERE user_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $newDefaultStmt->execute([$userId]);
        $newDefault = $newDefaultStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($newDefault) {
            $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ?")->execute([$newDefault['id']]);
        }
    }
    
    ResponseHandler::success([], 'Address deleted successfully');
}

/*********************************
 * SET DEFAULT ADDRESS
 *********************************/
function setDefaultAddress($conn, $addressId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $checkStmt = $conn->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
    $checkStmt->execute([$addressId, $userId]);
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Address not found', 404);
    }
    
    $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
    $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ?");
    $stmt->execute([$addressId]);
    
    ResponseHandler::success([], 'Default address updated');
}
?>