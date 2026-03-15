<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
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

/*********************************
 * GET USER FROM SESSION/AUTH
 * This pre-fills data from users table
 *********************************/
function getUserFromSession() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare(
        "SELECT 
            id,
            full_name,
            email,
            phone,
            address as default_address,
            city as default_city,
            member_level,
            member_points,
            verified,
            created_at as member_since
         FROM users 
         WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $user['verified'] = (bool)$user['verified'];
        $user['member_points'] = (int)$user['member_points'];
    }
    
    return $user;
}

/*********************************
 * AUTHENTICATION MIDDLEWARE
 *********************************/
function authenticateUser() {
    // Check session authentication first
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    // Check for API token/header authentication
    $headers = getallheaders();
    $userId = $headers['X-User-Id'] ?? $headers['x-user-id'] ?? null;
    $sessionToken = $headers['X-Session-Token'] ?? $headers['x-session-token'] ?? null;
    
    if ($userId && $sessionToken) {
        // Validate session token from database
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions 
             WHERE user_id = :user_id 
             AND session_id = :session_token 
             AND expires_at > NOW() 
             AND logout_at IS NULL"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_token' => $sessionToken
        ]);
        
        if ($stmt->fetch()) {
            $_SESSION['user_id'] = $userId;
            return $userId;
        }
    }
    
    // Check for Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        // Validate JWT or other token format
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM users 
             WHERE remember_token = :token 
             AND reset_token_expires > NOW()"
        );
        $stmt->execute([':token' => $token]);
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['user_id'] = $user['user_id'];
            return $user['user_id'];
        }
    }
    
    return null;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = str_replace('/api/addresses', '', $path);
    
    // Extract location ID from path if present
    $locationId = null;
    if (preg_match('/\/(\d+)$/', $path, $matches)) {
        $locationId = $matches[1];
    }

    if ($method === 'GET') {
        if ($locationId) {
            getLocationDetails($locationId);
        } else {
            getLocationsList();
        }
    } elseif ($method === 'POST') {
        handlePostRequest();
    } elseif ($method === 'PUT') {
        if ($locationId) {
            updateLocation($locationId);
        } else {
            ResponseHandler::error('Location ID required for update', 400);
        }
    } elseif ($method === 'PATCH') {
        handlePatchRequest($locationId);
    } elseif ($method === 'DELETE') {
        if ($locationId) {
            deleteLocation($locationId);
        } else {
            ResponseHandler::error('Location ID required for deletion', 400);
        }
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET LOCATIONS LIST
 *********************************/
function getLocationsList() {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Get user details from auth for pre-filling
    $user = getUserFromSession();
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get query parameters
    $params = $_GET;
    $type = $params['type'] ?? '';
    $isDefault = $params['is_default'] ?? null;
    $sortBy = $params['sort_by'] ?? 'last_used';
    $sortOrder = strtoupper($params['sort_order'] ?? 'DESC');
    $search = $params['search'] ?? '';
    $limit = min(100, max(1, intval($params['limit'] ?? 20)));
    $page = max(1, intval($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    // Build WHERE clause
    $whereConditions = ["user_id = :user_id"];
    $queryParams = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $whereConditions[] = "location_type = :type";
        $queryParams[':type'] = $type;
    }

    if ($isDefault !== null) {
        $whereConditions[] = "is_default = :is_default";
        $queryParams[':is_default'] = $isDefault === 'true' ? 1 : 0;
    }

    if ($search) {
        $whereConditions[] = "(label LIKE :search OR address_line1 LIKE :search OR area LIKE :search OR sector LIKE :search OR landmark LIKE :search)";
        $queryParams[':search'] = "%$search%";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['last_used', 'created_at', 'label', 'is_default', 'updated_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'last_used';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM addresses $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($queryParams);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get locations with pagination
    $sql = "SELECT 
                id,
                user_id,
                label,
                full_name,  -- This might be overridden per address
                phone,       -- This might be overridden per address
                address_line1,
                address_line2,
                city,
                neighborhood,
                area,
                sector,
                location_type,
                landmark,
                latitude,
                longitude,
                is_default,
                last_used,
                created_at,
                updated_at
            FROM addresses
            $whereClause
            ORDER BY is_default DESC, $sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format location data with user details from auth
    $formattedLocations = [];
    $currentLocation = null;
    
    foreach ($locations as $loc) {
        $formattedLocation = formatLocationData($loc, $user);
        $formattedLocations[] = $formattedLocation;
        
        // Identify current location (default or most recent)
        if ($loc['is_default']) {
            $currentLocation = $formattedLocation;
        }
    }

    // If no default found, use most recent
    if (!$currentLocation && !empty($formattedLocations)) {
        usort($formattedLocations, function($a, $b) {
            $timeA = strtotime($a['last_used'] ?? $a['created_at']);
            $timeB = strtotime($b['last_used'] ?? $b['created_at']);
            return $timeB - $timeA;
        });
        $currentLocation = $formattedLocations[0];
    }

    // Get user's statistics from database
    $statsStmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_locations,
            SUM(CASE WHEN is_default = 1 THEN 1 ELSE 0 END) as default_locations,
            SUM(CASE WHEN location_type = 'home' THEN 1 ELSE 0 END) as home_locations,
            SUM(CASE WHEN location_type = 'work' THEN 1 ELSE 0 END) as work_locations,
            SUM(CASE WHEN location_type = 'other' THEN 1 ELSE 0 END) as other_locations
        FROM addresses 
        WHERE user_id = :user_id"
    );
    $statsStmt->execute([':user_id' => $userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get Lilongwe areas and sectors from database if available
    $areasSectors = getAreasAndSectorsFromDB($conn);

    ResponseHandler::success([
        'locations' => $formattedLocations,
        'current_location' => $currentLocation,
        'user' => $user, // Include user details from auth for pre-filling new addresses
        'total_count' => $totalCount,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($totalCount / $limit),
            'has_next' => ($page * $limit) < $totalCount,
            'has_prev' => $page > 1
        ],
        'statistics' => [
            'total' => $stats['total_locations'] ?? 0,
            'default' => $stats['default_locations'] ?? 0,
            'home' => $stats['home_locations'] ?? 0,
            'work' => $stats['work_locations'] ?? 0,
            'other' => $stats['other_locations'] ?? 0
        ],
        'areas_sectors' => $areasSectors
    ]);
}

/*********************************
 * GET LOCATION DETAILS
 *********************************/
function getLocationDetails($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Get user details from auth
    $user = getUserFromSession();
    
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare(
        "SELECT 
            id,
            user_id,
            label,
            full_name,
            phone,
            address_line1,
            address_line2,
            city,
            neighborhood,
            area,
            sector,
            location_type,
            landmark,
            latitude,
            longitude,
            is_default,
            last_used,
            created_at,
            updated_at
        FROM addresses 
        WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    $formattedLocation = formatLocationData($location, $user);
    
    // Get order history if any
    $orderStmt = $conn->prepare(
        "SELECT 
            o.id,
            o.order_number,
            o.merchant_id,
            m.name as merchant_name,
            o.total_amount,
            o.status,
            o.created_at
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.user_id = :user_id 
            AND (o.delivery_address LIKE CONCAT('%', :address, '%') 
                OR o.delivery_address LIKE CONCAT('%', :label, '%'))
        ORDER BY o.created_at DESC
        LIMIT 5"
    );
    
    $orderStmt->execute([
        ':user_id' => $userId,
        ':address' => $location['address_line1'],
        ':label' => $location['label']
    ]);
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedLocation['order_history'] = $orders;

    // Get nearby locations
    $nearbyStmt = $conn->prepare(
        "SELECT 
            id,
            label,
            area,
            sector,
            location_type,
            latitude,
            longitude,
            (6371 * acos(cos(radians(:lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians(:lng)) + sin(radians(:lat)) * sin(radians(latitude)))) AS distance
        FROM addresses 
        WHERE user_id = :user_id 
            AND id != :location_id
            AND latitude IS NOT NULL 
            AND longitude IS NOT NULL
        HAVING distance < 5
        ORDER BY distance ASC
        LIMIT 3"
    );
    
    if ($location['latitude'] && $location['longitude']) {
        $nearbyStmt->execute([
            ':user_id' => $userId,
            ':location_id' => $locationId,
            ':lat' => $location['latitude'],
            ':lng' => $location['longitude']
        ]);
        $nearbyLocations = $nearbyStmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedLocation['nearby_locations'] = $nearbyLocations;
    } else {
        $formattedLocation['nearby_locations'] = [];
    }

    ResponseHandler::success([
        'location' => $formattedLocation,
        'user' => $user // Include user details from auth
    ]);
}

/*********************************
 * CREATE NEW LOCATION
 * Pre-fills from auth unless overridden
 *********************************/
function createLocation() {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Get user details from auth for pre-filling
    $user = getUserFromSession();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields - only address fields are required
    $required = ['label', 'address_line1', 'city'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            ResponseHandler::error("$field is required", 400);
        }
    }

    $label = trim($input['label']);
    $addressLine1 = trim($input['address_line1']);
    $addressLine2 = trim($input['address_line2'] ?? '');
    $city = trim($input['city']);
    $neighborhood = trim($input['neighborhood'] ?? '');
    $area = trim($input['area'] ?? $city);
    $sector = trim($input['sector'] ?? $neighborhood);
    $locationType = trim($input['location_type'] ?? 'other');
    $landmark = trim($input['landmark'] ?? '');
    $latitude = isset($input['latitude']) ? floatval($input['latitude']) : null;
    $longitude = isset($input['longitude']) ? floatval($input['longitude']) : null;
    $isDefault = boolval($input['is_default'] ?? false);
    
    // PRE-FILL FROM AUTH: Use auth data for contact info, but allow override from input
    // This way users can use different contact info for different delivery addresses
    $fullName = trim($input['full_name'] ?? $user['full_name'] ?? '');
    $phone = trim($input['phone'] ?? $user['phone'] ?? '');

    // Validate phone if provided (either from auth or override)
    if (empty($phone)) {
        ResponseHandler::error('Phone number is required for delivery', 400);
    }
    
    if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $phone)) {
        ResponseHandler::error('Invalid phone number format', 400);
    }

    // Validate location type
    $validTypes = ['home', 'work', 'other'];
    if (!in_array($locationType, $validTypes)) {
        ResponseHandler::error("Invalid location type. Must be one of: " . implode(', ', $validTypes), 400);
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    // Start transaction
    $conn->beginTransaction();

    try {
        // Check for duplicate address
        $duplicateStmt = $conn->prepare(
            "SELECT id FROM addresses 
             WHERE user_id = :user_id 
             AND address_line1 = :address_line1 
             AND city = :city 
             AND area = :area"
        );
        $duplicateStmt->execute([
            ':user_id' => $userId,
            ':address_line1' => $addressLine1,
            ':city' => $city,
            ':area' => $area
        ]);
        
        if ($duplicateStmt->fetch()) {
            ResponseHandler::error('This address already exists in your saved locations', 409);
        }

        // If setting as default, remove default from other locations
        if ($isDefault) {
            $updateStmt = $conn->prepare(
                "UPDATE addresses 
                 SET is_default = 0 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $updateStmt->execute([':user_id' => $userId]);
        }

        // Create the new location with contact info (pre-filled from auth unless overridden)
        $stmt = $conn->prepare(
            "INSERT INTO addresses 
                (user_id, label, full_name, phone, address_line1, address_line2, 
                 city, neighborhood, area, sector, location_type, landmark, 
                 latitude, longitude, is_default, created_at, last_used)
             VALUES 
                (:user_id, :label, :full_name, :phone, :address_line1, :address_line2, 
                 :city, :neighborhood, :area, :sector, :location_type, :landmark, 
                 :latitude, :longitude, :is_default, NOW(), NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':label' => $label,
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':address_line1' => $addressLine1,
            ':address_line2' => $addressLine2,
            ':city' => $city,
            ':neighborhood' => $neighborhood,
            ':area' => $area,
            ':sector' => $sector,
            ':location_type' => $locationType,
            ':landmark' => $landmark,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':is_default' => $isDefault ? 1 : 0
        ]);

        $locationId = $conn->lastInsertId();

        // If no default exists and this isn't set as default, set it as default
        if (!$isDefault) {
            $checkDefaultStmt = $conn->prepare(
                "SELECT COUNT(*) as default_count 
                 FROM addresses 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $checkDefaultStmt->execute([':user_id' => $userId]);
            $defaultCount = $checkDefaultStmt->fetch(PDO::FETCH_ASSOC)['default_count'];

            if ($defaultCount == 0) {
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $locationId]);
                $isDefault = true;
            }
        }

        $conn->commit();

        // Get the created location
        $locationStmt = $conn->prepare(
            "SELECT 
                id,
                user_id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                neighborhood,
                area,
                sector,
                location_type,
                landmark,
                latitude,
                longitude,
                is_default,
                last_used,
                created_at,
                updated_at
             FROM addresses 
             WHERE id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        $formattedLocation = formatLocationData($location, $user);

        // Log activity
        logUserActivity($conn, $userId, 'location_created', "Created location: $label", [
            'location_id' => $locationId,
            'location_type' => $locationType,
            'is_default' => $isDefault,
            'used_auth_data' => empty($input['full_name']) && empty($input['phone']) // Track if used auth pre-fill
        ]);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'user' => $user, // Include user details from auth
            'message' => 'Location created successfully',
            'prefilled_from_auth' => empty($input['full_name']) && empty($input['phone']) // Indicate if we used auth data
        ], 201);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LOCATION
 *********************************/
function updateLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Get user details from auth
    $user = getUserFromSession();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT * FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $currentLocation = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentLocation) {
        ResponseHandler::error('Location not found', 404);
    }

    // Prepare update data
    $updateFields = [];
    $params = [':id' => $locationId];

    if (isset($input['label'])) {
        $updateFields[] = "label = :label";
        $params[':label'] = trim($input['label']);
    }

    if (isset($input['full_name'])) {
        // Allow override of full name per address
        $updateFields[] = "full_name = :full_name";
        $params[':full_name'] = trim($input['full_name']);
    }

    if (isset($input['phone'])) {
        $phone = trim($input['phone']);
        if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $phone)) {
            ResponseHandler::error('Invalid phone number format', 400);
        }
        $updateFields[] = "phone = :phone";
        $params[':phone'] = $phone;
    }

    if (isset($input['address_line1'])) {
        $updateFields[] = "address_line1 = :address_line1";
        $params[':address_line1'] = trim($input['address_line1']);
    }

    if (isset($input['address_line2'])) {
        $updateFields[] = "address_line2 = :address_line2";
        $params[':address_line2'] = trim($input['address_line2']);
    }

    if (isset($input['city'])) {
        $updateFields[] = "city = :city";
        $params[':city'] = trim($input['city']);
    }

    if (isset($input['neighborhood'])) {
        $updateFields[] = "neighborhood = :neighborhood";
        $params[':neighborhood'] = trim($input['neighborhood']);
    }

    if (isset($input['area'])) {
        $updateFields[] = "area = :area";
        $params[':area'] = trim($input['area']);
    }

    if (isset($input['sector'])) {
        $updateFields[] = "sector = :sector";
        $params[':sector'] = trim($input['sector']);
    }

    if (isset($input['location_type'])) {
        $locationType = trim($input['location_type']);
        $validTypes = ['home', 'work', 'other'];
        if (!in_array($locationType, $validTypes)) {
            ResponseHandler::error("Invalid location type. Must be one of: " . implode(', ', $validTypes), 400);
        }
        $updateFields[] = "location_type = :location_type";
        $params[':location_type'] = $locationType;
    }

    if (isset($input['landmark'])) {
        $updateFields[] = "landmark = :landmark";
        $params[':landmark'] = trim($input['landmark']);
    }

    if (isset($input['latitude'])) {
        $latitude = floatval($input['latitude']);
        if ($latitude < -90 || $latitude > 90) {
            ResponseHandler::error('Invalid latitude value', 400);
        }
        $updateFields[] = "latitude = :latitude";
        $params[':latitude'] = $latitude;
    }

    if (isset($input['longitude'])) {
        $longitude = floatval($input['longitude']);
        if ($longitude < -180 || $longitude > 180) {
            ResponseHandler::error('Invalid longitude value', 400);
        }
        $updateFields[] = "longitude = :longitude";
        $params[':longitude'] = $longitude;
    }

    if (empty($updateFields)) {
        ResponseHandler::error('No fields to update', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Handle default location change
        if (isset($input['is_default']) && boolval($input['is_default']) !== boolval($currentLocation['is_default'])) {
            if (boolval($input['is_default'])) {
                // Remove default from other locations
                $removeDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 0 
                     WHERE user_id = :user_id AND is_default = 1"
                );
                $removeDefaultStmt->execute([':user_id' => $userId]);

                $updateFields[] = "is_default = 1";
            } else {
                $updateFields[] = "is_default = 0";
            }
        }

        // Update the location
        $updateFields[] = "updated_at = NOW()";
        
        $sql = "UPDATE addresses SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $conn->commit();

        // Get updated location
        $locationStmt = $conn->prepare(
            "SELECT 
                id,
                user_id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                neighborhood,
                area,
                sector,
                location_type,
                landmark,
                latitude,
                longitude,
                is_default,
                last_used,
                created_at,
                updated_at
             FROM addresses 
             WHERE id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        $formattedLocation = formatLocationData($location, $user);

        // Log activity
        logUserActivity($conn, $userId, 'location_updated', "Updated location: {$currentLocation['label']}", [
            'location_id' => $locationId,
            'changes' => array_keys($input)
        ]);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'user' => $user,
            'message' => 'Location updated successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE LOCATION
 *********************************/
function deleteLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, label, is_default FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Check if location is used in any recent orders
    $orderCheckStmt = $conn->prepare(
        "SELECT COUNT(*) as order_count FROM orders 
         WHERE user_id = :user_id 
         AND delivery_address LIKE CONCAT('%', :label, '%')
         AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $orderCheckStmt->execute([
        ':user_id' => $userId,
        ':label' => $location['label']
    ]);
    $orderCount = $orderCheckStmt->fetch(PDO::FETCH_ASSOC)['order_count'];

    if ($orderCount > 0) {
        ResponseHandler::error('Cannot delete location that has been used in recent orders. Consider archiving instead.', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Delete the location
        $deleteStmt = $conn->prepare(
            "DELETE FROM addresses 
             WHERE id = :id AND user_id = :user_id"
        );
        $deleteStmt->execute([
            ':id' => $locationId,
            ':user_id' => $userId
        ]);

        // If deleting default location, set a new default
        if ($location['is_default']) {
            // Get another location to set as default
            $newDefaultStmt = $conn->prepare(
                "SELECT id FROM addresses 
                 WHERE user_id = :user_id 
                 ORDER BY last_used DESC, created_at DESC 
                 LIMIT 1"
            );
            $newDefaultStmt->execute([':user_id' => $userId]);
            $newDefault = $newDefaultStmt->fetch(PDO::FETCH_ASSOC);

            if ($newDefault) {
                // Set new default
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $newDefault['id']]);
            }
        }

        $conn->commit();

        // Log activity
        logUserActivity($conn, $userId, 'location_deleted', "Deleted location: {$location['label']}", [
            'location_id' => $locationId,
            'was_default' => $location['is_default']
        ]);

        ResponseHandler::success([
            'message' => 'Location deleted successfully',
            'deleted_id' => $locationId
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to delete location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE POST REQUESTS
 *********************************/
function handlePostRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create_location':
            createLocation();
            break;
        case 'search_locations':
            searchLocations($input);
            break;
        case 'validate_address':
            validateAddress($input);
            break;
        case 'get_areas_sectors':
            getAreasAndSectors();
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * HANDLE PATCH REQUESTS
 *********************************/
function handlePatchRequest($locationId = null) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'set_default':
            setDefaultLocation($locationId);
            break;
        case 'update_last_used':
            updateLastUsed($locationId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * SET DEFAULT LOCATION
 *********************************/
function setDefaultLocation($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, label FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Remove default from all locations
        $removeDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 0 
             WHERE user_id = :user_id AND is_default = 1"
        );
        $removeDefaultStmt->execute([':user_id' => $userId]);

        // Set the specified location as default
        $setDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 1, 
                 last_used = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $setDefaultStmt->execute([':id' => $locationId]);

        $conn->commit();

        // Get updated location
        $locationStmt = $conn->prepare(
            "SELECT 
                id,
                user_id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                neighborhood,
                area,
                sector,
                location_type,
                landmark,
                latitude,
                longitude,
                is_default,
                last_used,
                created_at,
                updated_at
             FROM addresses 
             WHERE id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        // Get user details
        $user = getUserFromSession();
        $formattedLocation = formatLocationData($location, $user);

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Default location updated successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to set default location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LAST USED TIMESTAMP
 *********************************/
function updateLastUsed($locationId) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Location not found', 404);
    }

    try {
        // Update last used timestamp
        $stmt = $conn->prepare(
            "UPDATE addresses 
             SET last_used = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':id' => $locationId]);

        ResponseHandler::success([
            'message' => 'Last used timestamp updated',
            'location_id' => $locationId
        ]);

    } catch (Exception $e) {
        ResponseHandler::error('Failed to update timestamp: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * SEARCH LOCATIONS
 *********************************/
function searchLocations($input) {
    $userId = authenticateUser();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Get user details from auth
    $user = getUserFromSession();
    
    $query = trim($input['query'] ?? '');
    $city = $input['city'] ?? '';
    $area = $input['area'] ?? '';
    $sector = $input['sector'] ?? '';
    $limit = min(50, max(1, intval($input['limit'] ?? 10)));
    
    if (empty($query) && empty($city) && empty($area) && empty($sector)) {
        ResponseHandler::error('Search query or filters required', 400);
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];
    
    if ($query) {
        $whereConditions[] = "(label LIKE :query OR address_line1 LIKE :query OR landmark LIKE :query)";
        $params[':query'] = "%$query%";
    }
    
    if ($city) {
        $whereConditions[] = "city = :city";
        $params[':city'] = $city;
    }
    
    if ($area) {
        $whereConditions[] = "area = :area";
        $params[':area'] = $area;
    }
    
    if ($sector) {
        $whereConditions[] = "sector = :sector";
        $params[':sector'] = $sector;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    $sql = "SELECT 
                id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                area,
                sector,
                landmark,
                location_type,
                is_default,
                last_used
            FROM addresses
            $whereClause
            ORDER BY 
                CASE 
                    WHEN label LIKE :query_exact THEN 1
                    WHEN address_line1 LIKE :query_exact THEN 2
                    WHEN area LIKE :query_exact THEN 3
                    ELSE 4
                END,
                is_default DESC,
                last_used DESC
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':query_exact', "$query%");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format location data
    $formattedLocations = [];
    foreach ($locations as $loc) {
        $formattedLocations[] = formatLocationData($loc, $user);
    }
    
    ResponseHandler::success([
        'locations' => $formattedLocations,
        'user' => $user,
        'total_count' => count($formattedLocations)
    ]);
}

/*********************************
 * VALIDATE ADDRESS
 *********************************/
function validateAddress($input) {
    $address = $input['address'] ?? '';
    $city = $input['city'] ?? '';

    if (empty($address)) {
        ResponseHandler::error('Address is required', 400);
    }

    $errors = [];
    
    // Check address length
    if (strlen($address) < 5) {
        $errors[] = 'Address is too short (minimum 5 characters)';
    }
    
    if (strlen($address) > 500) {
        $errors[] = 'Address is too long (maximum 500 characters)';
    }
    
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    
    // Check for invalid characters
    if (preg_match('/[<>{}[\]]/', $address)) {
        $errors[] = 'Address contains invalid characters';
    }

    if (!empty($errors)) {
        ResponseHandler::success([
            'valid' => false,
            'errors' => $errors
        ]);
    } else {
        ResponseHandler::success([
            'valid' => true,
            'errors' => []
        ]);
    }
}

/*********************************
 * GET AREAS AND SECTORS
 *********************************/
function getAreasAndSectors() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $areasSectors = getAreasAndSectorsFromDB($conn);
    
    ResponseHandler::success($areasSectors);
}

/*********************************
 * GET AREAS AND SECTORS FROM DATABASE
 *********************************/
function getAreasAndSectorsFromDB($conn) {
    // Get unique areas from addresses table
    $areasStmt = $conn->query(
        "SELECT DISTINCT area 
         FROM addresses 
         WHERE area IS NOT NULL AND area != '' 
         ORDER BY area"
    );
    $areas = $areasStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique sectors from addresses table
    $sectorsStmt = $conn->query(
        "SELECT DISTINCT sector 
         FROM addresses 
         WHERE sector IS NOT NULL AND sector != '' 
         ORDER BY sector"
    );
    $sectors = $sectorsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique cities from addresses table
    $citiesStmt = $conn->query(
        "SELECT DISTINCT city 
         FROM addresses 
         WHERE city IS NOT NULL AND city != '' 
         ORDER BY city"
    );
    $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    return [
        'areas' => $areas ?: [],
        'sectors' => $sectors ?: [],
        'cities' => $cities ?: ['Lilongwe', 'Blantyre', 'Mzuzu']
    ];
}

/*********************************
 * FORMAT LOCATION DATA
 * Uses auth data as fallback for contact info
 *********************************/
function formatLocationData($loc, $user = null) {
    if (empty($loc)) {
        return null;
    }
    
    // Generate display addresses
    $displayAddress = generateDisplayAddress($loc);
    $shortAddress = generateShortAddress($loc);
    
    // Get type info
    $typeInfo = getLocationTypeInfo($loc['location_type'] ?? 'other');
    
    // Use location-specific contact info if available, otherwise fallback to auth data
    $fullName = !empty($loc['full_name']) ? $loc['full_name'] : ($user['full_name'] ?? '');
    $phone = !empty($loc['phone']) ? $loc['phone'] : ($user['phone'] ?? '');
    
    return [
        'id' => $loc['id'],
        'user_id' => $loc['user_id'] ?? null,
        'name' => $loc['label'] ?? '',
        // Contact info: use location-specific if available, otherwise auth data
        'full_name' => $fullName,
        'phone' => $phone,
        'address' => $loc['address_line1'] ?? '',
        'apartment' => $loc['address_line2'] ?? '',
        'city' => $loc['city'] ?? '',
        'area' => $loc['area'] ?? '',
        'sector' => $loc['sector'] ?? '',
        'neighborhood' => $loc['neighborhood'] ?? '',
        'landmark' => $loc['landmark'] ?? '',
        'type' => $loc['location_type'] ?? 'other',
        'is_default' => boolval($loc['is_default'] ?? false),
        'last_used' => $loc['last_used'] ?? null,
        'latitude' => isset($loc['latitude']) ? floatval($loc['latitude']) : null,
        'longitude' => isset($loc['longitude']) ? floatval($loc['longitude']) : null,
        'created_at' => $loc['created_at'] ?? null,
        'updated_at' => $loc['updated_at'] ?? null,
        'display_address' => $displayAddress,
        'short_address' => $shortAddress,
        'type_icon' => $typeInfo['icon'],
        'type_color' => $typeInfo['color'],
        // Indicate if contact info came from auth or is location-specific
        'contact_source' => empty($loc['full_name']) && empty($loc['phone']) ? 'auth' : 'location_specific'
    ];
}

/*********************************
 * GENERATE DISPLAY ADDRESS
 *********************************/
function generateDisplayAddress($loc) {
    $parts = [];
    
    if (!empty($loc['address_line1'])) {
        $parts[] = $loc['address_line1'];
    }
    
    if (!empty($loc['address_line2'])) {
        $parts[] = $loc['address_line2'];
    }
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    if (!empty($loc['sector']) && !empty($loc['area'])) {
        $parts[] = $loc['sector'] . ', ' . $loc['area'];
    } elseif (!empty($loc['area'])) {
        $parts[] = $loc['area'];
    }
    
    if (!empty($loc['city'])) {
        $parts[] = $loc['city'];
    }
    
    return implode(', ', array_filter($parts));
}

/*********************************
 * GENERATE SHORT ADDRESS
 *********************************/
function generateShortAddress($loc) {
    $parts = [];
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    if (!empty($loc['area'])) {
        $parts[] = $loc['area'];
    }
    
    if (!empty($loc['sector'])) {
        $parts[] = $loc['sector'];
    }
    
    return implode(', ', $parts);
}

/*********************************
 * GET LOCATION TYPE INFO
 *********************************/
function getLocationTypeInfo($type) {
    $types = [
        'home' => [
            'icon' => 'home',
            'color' => '#2196F3' // Blue
        ],
        'work' => [
            'icon' => 'work',
            'color' => '#4CAF50' // Green
        ],
        'other' => [
            'icon' => 'location_on',
            'color' => '#FF9800' // Orange
        ]
    ];

    return $types[$type] ?? $types['other'];
}

/*********************************
 * LOG USER ACTIVITY
 *********************************/
function logUserActivity($conn, $userId, $activityType, $description, $metadata = null) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO user_activities 
                (user_id, activity_type, description, ip_address, user_agent, metadata, created_at)
             VALUES 
                (:user_id, :activity_type, :description, :ip_address, :user_agent, :metadata, NOW())"
        );
        
        $metaJson = $metadata ? json_encode($metadata) : null;
        
        $stmt->execute([
            ':user_id' => $userId,
            ':activity_type' => $activityType,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':metadata' => $metaJson
        ]);
    } catch (Exception $e) {
        // Silently fail logging
        error_log('Failed to log user activity: ' . $e->getMessage());
    }
}
?>