<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            return $user['id'];
        }
    }
    
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions WHERE session_token = :token AND expires_at > NOW()"
        );
        $stmt->execute([':token' => $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $_SESSION['user_id'] = $result['user_id'];
            return $result['user_id'];
        }
        
        if (session_id() !== $sessionToken) {
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                return $_SESSION['user_id'];
            }
        }
    }
    
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }

    switch ($method) {
        case 'GET':
            handleGetRequest($path, $_GET);
            break;
        case 'POST':
            handlePostRequest($path, $input);
            break;
        case 'PUT':
            handlePutRequest($path, $input);
            break;
        case 'DELETE':
            handleDeleteRequest($path, $input);
            break;
        default:
            ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($path, $queryParams) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    $sessionId = session_id();

    if (strpos($path, '/cart') !== false) {
        $cartId = $queryParams['cart_id'] ?? null;
        $action = $queryParams['action'] ?? '';
        
        if ($action === 'count') {
            getCartItemCount($conn, $userId, $sessionId);
        } elseif ($action === 'summary') {
            getCartSummary($conn, $userId, $sessionId, $baseUrl);
        } elseif ($action === 'items') {
            getCartItems($conn, $userId, $sessionId, $baseUrl);
        } elseif ($cartId) {
            getCartDetails($conn, $cartId, $baseUrl, $userId);
        } else {
            getCurrentCart($conn, $userId, $sessionId, $baseUrl);
        }
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * GET OR CREATE USER CART
 *********************************/
function getOrCreateUserCart($conn, $userId = null, $sessionId = null) {
    if (!$sessionId) {
        $sessionId = session_id();
    }
    
    // Try to find cart by user_id if logged in
    if ($userId) {
        $stmt = $conn->prepare(
            "SELECT * FROM carts 
             WHERE user_id = :user_id AND status = 'active'
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cart) {
            return $cart;
        }
    }
    
    // Try to find cart by session_id for guest
    $stmt = $conn->prepare(
        "SELECT * FROM carts 
         WHERE session_id = :session_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':session_id' => $sessionId]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cart) {
        return $cart;
    }
    
    // Create new cart
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    $insertStmt = $conn->prepare(
        "INSERT INTO carts (user_id, session_id, status, ip_address, user_agent, expires_at, created_at, updated_at)
         VALUES (:user_id, :session_id, 'active', :ip_address, :user_agent, :expires_at, NOW(), NOW())"
    );
    
    try {
        $insertStmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':expires_at' => $expiresAt
        ]);
        $cartId = $conn->lastInsertId();
        
        return [
            'id' => $cartId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'status' => 'active',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    } catch (Exception $e) {
        error_log("Failed to create cart: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * GET CURRENT CART
 *********************************/
function getCurrentCart($conn, $userId, $sessionId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    if (!$cart) {
        ResponseHandler::error('Failed to retrieve cart', 500);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    $promotion = getCartPromotion($conn, $cart['id']);
    $totals = calculateCartTotals($conn, $cart['id'], $cartItems);
    
    // Get merchant grouping
    $groupedItems = groupCartItemsByMerchant($cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'cart' => [
                'id' => $cart['id'],
                'user_id' => $cart['user_id'],
                'session_id' => $cart['session_id'],
                'status' => $cart['status'],
                'expires_at' => $cart['expires_at'],
                'created_at' => $cart['created_at'],
                'updated_at' => $cart['updated_at']
            ],
            'items' => $cartItems,
            'grouped_by_merchant' => $groupedItems,
            'promotion' => $promotion,
            'summary' => $totals,
            'is_eligible_for_checkout' => !empty($cartItems)
        ]
    ]);
}

/*********************************
 * GET CART ITEMS
 *********************************/
function getCartItems($conn, $userId, $sessionId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    if (!$cart) {
        ResponseHandler::success([
            'success' => true,
            'data' => []
        ]);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    
    ResponseHandler::success([
        'success' => true,
        'data' => $cartItems
    ]);
}

/*********************************
 * GET CART DETAILS
 *********************************/
function getCartDetails($conn, $cartId, $baseUrl, $userId) {
    $cartStmt = $conn->prepare(
        "SELECT * FROM carts WHERE id = :id"
    );
    $cartStmt->execute([':id' => $cartId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        ResponseHandler::error('Cart not found', 404);
    }
    
    // Verify ownership
    if ($cart['user_id'] && $cart['user_id'] != $userId) {
        ResponseHandler::error('Unauthorized', 403);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cartId, $baseUrl);
    $promotion = getCartPromotion($conn, $cartId);
    $totals = calculateCartTotals($conn, $cartId, $cartItems);
    $groupedItems = groupCartItemsByMerchant($cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'cart' => $cart,
            'items' => $cartItems,
            'grouped_by_merchant' => $groupedItems,
            'promotion' => $promotion,
            'summary' => $totals
        ]
    ]);
}

/*********************************
 * GET CART SUMMARY
 *********************************/
function getCartSummary($conn, $userId, $sessionId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    if (!$cart) {
        ResponseHandler::success([
            'success' => true,
            'data' => [
                'item_count' => 0,
                'total_quantity' => 0,
                'subtotal' => 0,
                'has_items' => false
            ]
        ]);
    }
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(id) as item_count,
            SUM(quantity) as total_quantity,
            SUM(price * quantity) as subtotal
         FROM cart_items 
         WHERE cart_id = :cart_id AND is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cart['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'item_count' => intval($result['item_count'] ?? 0),
            'total_quantity' => intval($result['total_quantity'] ?? 0),
            'subtotal' => floatval($result['subtotal'] ?? 0),
            'has_items' => ($result['item_count'] ?? 0) > 0,
            'cart_id' => $cart['id']
        ]
    ]);
}

/*********************************
 * GET CART ITEM COUNT
 *********************************/
function getCartItemCount($conn, $userId, $sessionId) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    if (!$cart) {
        ResponseHandler::success([
            'success' => true,
            'data' => [
                'item_count' => 0,
                'total_quantity' => 0,
                'has_cart' => false
            ]
        ]);
    }
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(id) as item_count, 
            SUM(quantity) as total_quantity
         FROM cart_items 
         WHERE cart_id = :cart_id AND is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cart['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'success' => true,
        'data' => [
            'item_count' => intval($result['item_count'] ?? 0),
            'total_quantity' => intval($result['total_quantity'] ?? 0),
            'has_cart' => true,
            'cart_id' => $cart['id']
        ]
    ]);
}

/*********************************
 * GET CART ITEMS BY CART ID
 *********************************/
function getCartItemsByCartId($conn, $cartId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.*,
            -- Menu item fields
            mi.name as menu_item_name,
            mi.description as menu_item_description,
            mi.image_url as menu_item_image,
            mi.category as menu_item_category,
            mi.unit_type,
            
            -- Quick order fields
            qo.title as quick_order_title,
            qo.description as quick_order_description,
            qo.image_url as quick_order_image,
            qo.category as quick_order_category,
            
            -- Quick order item fields
            qoi.name as quick_order_item_name,
            qoi.description as quick_order_item_description,
            qoi.image_url as quick_order_item_image,
            qoi.measurement_type,
            qoi.unit,
            qoi.quantity as item_quantity_value,
            
            -- Merchant fields
            m.id as merchant_id,
            m.name as merchant_name,
            m.image_url as merchant_image,
            m.logo_url as merchant_logo,
            m.rating as merchant_rating,
            m.is_open as merchant_is_open,
            m.delivery_fee,
            m.min_order_amount,
            m.free_delivery_threshold,
            m.delivery_time,
            m.preparation_time as merchant_preparation_time,
            m.business_type,
            m.cuisine_type,
            m.address as merchant_address,
            m.latitude,
            m.longitude,
            
            -- Determine source type
            CASE 
                WHEN qo.id IS NOT NULL THEN 'quick_order'
                ELSE 'menu_item'
            END as source_type
            
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
        LEFT JOIN quick_order_items qoi ON ci.quick_order_item_id = qoi.id
        LEFT JOIN merchants m ON ci.merchant_id = m.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get add-ons for each item
    foreach ($items as &$item) {
        $item['add_ons'] = getCartItemAddOns($conn, $item['id']);
    }
    
    return array_map(function($item) use ($baseUrl) {
        return formatCartItemData($item, $baseUrl);
    }, $items);
}

/*********************************
 * GET CART ITEM ADD-ONS
 *********************************/
function getCartItemAddOns($conn, $cartItemId) {
    $stmt = $conn->prepare(
        "SELECT * FROM cart_addons 
         WHERE cart_item_id = :cart_item_id
         ORDER BY created_at ASC"
    );
    $stmt->execute([':cart_item_id' => $cartItemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * GET CART PROMOTION - FIXED
 *********************************/
function getCartPromotion($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT cp.*, 
                p.id as promotion_id,
                p.title as name, 
                p.description, 
                p.discount_type, 
                p.discount_value,
                p.min_order_amount,
                p.start_date,
                p.end_date,
                p.is_active
         FROM cart_promotions cp
         LEFT JOIN promotions p ON cp.promotion_id = p.id
         WHERE cp.cart_id = :cart_id"
    );
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug log to see what's returned
    error_log("getCartPromotion result: " . json_encode($result));
    
    return $result;
}

/*********************************
 * GROUP CART ITEMS BY MERCHANT
 *********************************/
function groupCartItemsByMerchant($items) {
    $grouped = [];
    
    foreach ($items as $item) {
        $merchantId = $item['merchant_id'] ?? 0;
        
        if (!isset($grouped[$merchantId])) {
            $grouped[$merchantId] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $item['merchant_name'] ?? 'Unknown Merchant',
                'merchant_image' => $item['merchant_image'] ?? null,
                'merchant_logo' => $item['merchant_logo'] ?? null,
                'merchant_rating' => floatval($item['merchant_rating'] ?? 0),
                'merchant_is_open' => boolval($item['merchant_is_open'] ?? true),
                'delivery_fee' => floatval($item['delivery_fee'] ?? 0),
                'min_order' => floatval($item['min_order_amount'] ?? 0),
                'business_type' => $item['business_type'] ?? 'restaurant',
                'cuisine_types' => $item['cuisine_types'] ?? [],
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0,
                'total_quantity' => 0
            ];
        }
        
        $grouped[$merchantId]['items'][] = $item;
        $grouped[$merchantId]['subtotal'] += $item['total'] ?? 0;
        $grouped[$merchantId]['item_count'] += 1;
        $grouped[$merchantId]['total_quantity'] += $item['quantity'] ?? 1;
    }
    
    return array_values($grouped);
}

/*********************************
 * CALCULATE CART TOTALS
 *********************************/
function calculateCartTotals($conn, $cartId, $cartItems) {
    $subtotal = 0;
    $itemCount = 0;
    $totalQuantity = 0;
    $merchantIds = [];
    
    foreach ($cartItems as $item) {
        $subtotal += $item['total'] ?? 0;
        $itemCount++;
        $totalQuantity += $item['quantity'] ?? 1;
        if (!empty($item['merchant_id'])) {
            $merchantIds[$item['merchant_id']] = true;
        }
    }
    
    // Get promotion discount
    $promoStmt = $conn->prepare(
        "SELECT discount_amount FROM cart_promotions WHERE cart_id = :cart_id"
    );
    $promoStmt->execute([':cart_id' => $cartId]);
    $promoResult = $promoStmt->fetch(PDO::FETCH_ASSOC);
    $promotionDiscount = floatval($promoResult['discount_amount'] ?? 0);
    
    $adjustedSubtotal = max(0, $subtotal - $promotionDiscount);
    
    // Calculate delivery fee from merchants
    $deliveryFee = 0;
    foreach (array_keys($merchantIds) as $merchantId) {
        $merchantStmt = $conn->prepare(
            "SELECT delivery_fee FROM merchants WHERE id = :id"
        );
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        $deliveryFee += floatval($merchant['delivery_fee'] ?? 0);
    }
    
    return [
        'subtotal' => round($subtotal, 2),
        'discount_amount' => round($promotionDiscount, 2),
        'adjusted_subtotal' => round($adjustedSubtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'total_amount' => round($adjustedSubtotal + $deliveryFee, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity,
        'merchant_count' => count($merchantIds)
    ];
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    $sessionId = session_id();
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'add_item':
            addItemToCart($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        case 'add_quick_order':
            addQuickOrderToCart($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        case 'update_quantity':
            updateCartItemQuantity($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        case 'remove_item':
            removeCartItem($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        case 'clear_cart':
            clearCart($conn, $data, $userId, $sessionId);
            break;
        case 'apply_promotion':
            applyPromotionToCart($conn, $data, $userId, $sessionId);
            break;
        case 'remove_promotion':
            removePromotionFromCart($conn, $data, $userId, $sessionId);
            break;
        case 'save_for_later':
            toggleSaveForLater($conn, $data, $userId, $sessionId);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * ADD ITEM TO CART (MENU ITEM) - FIXED measurement_type
 *********************************/
function addItemToCart($conn, $data, $userId, $sessionId, $baseUrl) {
    $menuItemId = $data['menu_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $selectedOptions = $data['selected_options'] ?? null;
    
    if (!$menuItemId) {
        ResponseHandler::error('Menu item ID is required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    // Get item details with merchant info
    $itemStmt = $conn->prepare(
        "SELECT 
            mi.*,
            m.id as merchant_id,
            m.name as merchant_name,
            m.delivery_fee,
            m.min_order_amount,
            m.preparation_time as merchant_prep_time
         FROM menu_items mi
         LEFT JOIN merchants m ON mi.merchant_id = m.id
         WHERE mi.id = :item_id
         AND mi.is_available = 1
         AND m.is_active = 1"
    );
    
    $itemStmt->execute([':item_id' => $menuItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Item not available', 404);
    }
    
    // Get or create cart
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    // Check for existing item from different merchant
    $merchantCheck = $conn->prepare(
        "SELECT DISTINCT merchant_id, m.name as merchant_name
         FROM cart_items ci
         LEFT JOIN merchants m ON ci.merchant_id = m.id
         WHERE ci.cart_id = :cart_id 
         AND ci.is_active = 1
         AND ci.merchant_id IS NOT NULL
         LIMIT 1"
    );
    $merchantCheck->execute([':cart_id' => $cart['id']]);
    $existingMerchant = $merchantCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existingMerchant && $existingMerchant['merchant_id'] != $item['merchant_id']) {
        ResponseHandler::error(
            "Your cart already contains items from a different merchant. Please complete or clear that order first.",
            400
        );
    }
    
    // Check if item already in cart
    $existingStmt = $conn->prepare(
        "SELECT id, quantity FROM cart_items 
         WHERE cart_id = :cart_id 
         AND menu_item_id = :item_id
         AND is_active = 1"
    );
    
    $existingStmt->execute([
        ':cart_id' => $cart['id'],
        ':item_id' => $menuItemId
    ]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingItem) {
        $newQuantity = $existingItem['quantity'] + $quantity;
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity,
                 special_instructions = :instructions,
                 selected_options = :selected_options,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':instructions' => $specialInstructions,
            ':selected_options' => $selectedOptions ? json_encode($selectedOptions) : null,
            ':id' => $existingItem['id']
        ]);
        
        $cartItemId = $existingItem['id'];
        $message = 'Item quantity updated';
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items (
                cart_id, user_id, menu_item_id, merchant_id, merchant_name,
                name, description, price, image_url, quantity,
                measurement_type, unit, quantity_value, custom_unit,
                item_type, category, merchant_delivery_fee, merchant_min_order,
                preparation_time, special_instructions, selected_options,
                is_active, created_at, updated_at
            ) VALUES (
                :cart_id, :user_id, :menu_item_id, :merchant_id, :merchant_name,
                :name, :description, :price, :image_url, :quantity,
                :measurement_type, :unit, :quantity_value, :custom_unit,
                :item_type, :category, :delivery_fee, :min_order,
                :prep_time, :instructions, :selected_options,
                1, NOW(), NOW()
            )"
        );
        
        $insertStmt->execute([
            ':cart_id' => $cart['id'],
            ':user_id' => $userId,
            ':menu_item_id' => $menuItemId,
            ':merchant_id' => $item['merchant_id'],
            ':merchant_name' => $item['merchant_name'],
            ':name' => $item['name'],
            ':description' => $item['description'],
            ':price' => $item['price'],
            ':image_url' => $item['image_url'],
            ':quantity' => $quantity,
            ':measurement_type' => $item['measurement_type'] ?? 'count', // CHANGED from 'quantity' to 'count'
            ':unit' => $item['unit'] ?? null,
            ':quantity_value' => $item['quantity_value'] ?? null,
            ':custom_unit' => $item['custom_unit'] ?? null,
            ':item_type' => $item['item_type'] ?? 'food',
            ':category' => $item['category'] ?? '',
            ':delivery_fee' => $item['delivery_fee'] ?? 0,
            ':min_order' => $item['min_order_amount'] ?? 0,
            ':prep_time' => $item['merchant_prep_time'] ?? null,
            ':instructions' => $specialInstructions,
            ':selected_options' => $selectedOptions ? json_encode($selectedOptions) : null
        ]);
        
        $cartItemId = $conn->lastInsertId();
        $message = 'Item added to cart';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    // Get updated cart
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'message' => $message,
        'data' => [
            'cart_item_id' => $cartItemId,
            'cart_id' => $cart['id'],
            'cart_summary' => $totals
        ]
    ]);
}

/*********************************
 * ADD QUICK ORDER TO CART - FIXED measurement_type
 *********************************/
function addQuickOrderToCart($conn, $data, $userId, $sessionId, $baseUrl) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $quickOrderItemId = $data['quick_order_item_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $selectedAddOns = $data['selected_add_ons'] ?? [];
    $specialInstructions = $data['special_instructions'] ?? '';
    $variantId = $data['variant_id'] ?? null;
    $variantData = $data['variant_data'] ?? null;
    
    if (!$quickOrderId || !$merchantId) {
        ResponseHandler::error('Quick order ID and merchant ID are required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    // Get quick order details
    $qoStmt = $conn->prepare(
        "SELECT 
            qo.*,
            qom.custom_price,
            qom.custom_delivery_time,
            qoi.id as item_id,
            qoi.name as item_name,
            qoi.description as item_description,
            qoi.price as item_price,
            qoi.image_url as item_image,
            qoi.measurement_type,
            qoi.unit,
            qoi.quantity as item_quantity,
            qoi.custom_unit,
            qoi.has_variants,
            qoi.variants_json,
            qoi.max_quantity,
            qoi.stock_quantity,
            m.name as merchant_name,
            m.delivery_fee,
            m.min_order_amount,
            m.preparation_time as merchant_prep_time
         FROM quick_orders qo
         INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
         LEFT JOIN quick_order_items qoi ON qo.id = qoi.quick_order_id
         LEFT JOIN merchants m ON qom.merchant_id = m.id
         WHERE qo.id = :quick_order_id
         AND qom.merchant_id = :merchant_id
         AND qom.is_active = 1
         AND m.is_active = 1"
    );
    
    $qoStmt->execute([
        ':quick_order_id' => $quickOrderId,
        ':merchant_id' => $merchantId
    ]);
    
    $items = $qoStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        ResponseHandler::error('Quick order not available', 404);
    }
    
    // Get main quick order data from first row
    $quickOrder = $items[0];
    
    // Find specific item if requested
    $selectedItem = null;
    if ($quickOrderItemId) {
        foreach ($items as $item) {
            if ($item['item_id'] == $quickOrderItemId) {
                $selectedItem = $item;
                break;
            }
        }
        if (!$selectedItem) {
            ResponseHandler::error('Quick order item not found', 404);
        }
    } else {
        $selectedItem = $items[0];
        $quickOrderItemId = $selectedItem['item_id'];
    }
    
    // Calculate price
    $basePrice = floatval($quickOrder['custom_price'] ?? $quickOrder['price'] ?? 0);
    $itemPrice = floatval($selectedItem['item_price'] ?? 0);
    $finalPrice = $basePrice + $itemPrice;
    
    // Handle variant
    $variantName = '';
    if ($variantId && !empty($selectedItem['variants_json'])) {
        $variants = json_decode($selectedItem['variants_json'], true) ?? [];
        foreach ($variants as $variant) {
            if (($variant['id'] ?? null) == $variantId) {
                $variantName = ' (' . ($variant['name'] ?? '') . ')';
                $finalPrice += floatval($variant['price'] ?? 0);
                if (!$variantData) {
                    $variantData = $variant;
                }
                break;
            }
        }
    }
    
    // Get or create cart
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    // Check for existing item from different merchant
    $merchantCheck = $conn->prepare(
        "SELECT DISTINCT merchant_id, merchant_name
         FROM cart_items 
         WHERE cart_id = :cart_id 
         AND is_active = 1
         AND merchant_id IS NOT NULL
         LIMIT 1"
    );
    $merchantCheck->execute([':cart_id' => $cart['id']]);
    $existingMerchant = $merchantCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existingMerchant && $existingMerchant['merchant_id'] != $merchantId) {
        ResponseHandler::error(
            "Your cart already contains items from a different merchant. Please complete or clear that order first.",
            400
        );
    }
    
    // Check if item already in cart
    $existingStmt = $conn->prepare(
        "SELECT id, quantity FROM cart_items 
         WHERE cart_id = :cart_id 
         AND quick_order_id = :quick_order_id
         AND quick_order_item_id = :item_id
         AND (variant_id = :variant_id OR (:variant_id IS NULL AND variant_id IS NULL))
         AND is_active = 1"
    );
    
    $existingStmt->execute([
        ':cart_id' => $cart['id'],
        ':quick_order_id' => $quickOrderId,
        ':item_id' => $quickOrderItemId,
        ':variant_id' => $variantId
    ]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingItem) {
        $newQuantity = $existingItem['quantity'] + $quantity;
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity,
                 special_instructions = :instructions,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':instructions' => $specialInstructions,
            ':id' => $existingItem['id']
        ]);
        
        $cartItemId = $existingItem['id'];
        $message = 'Quick order quantity updated';
    } else {
        $itemName = $selectedItem['item_name'] . $variantName;
        
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items (
                cart_id, user_id, quick_order_id, quick_order_item_id,
                merchant_id, merchant_name, name, description, price,
                image_url, quantity, measurement_type, unit, quantity_value,
                custom_unit, item_type, category, merchant_delivery_fee,
                merchant_min_order, preparation_time, variant_id, variant_data,
                variant_name, has_variants, special_instructions,
                is_active, created_at, updated_at
            ) VALUES (
                :cart_id, :user_id, :quick_order_id, :quick_order_item_id,
                :merchant_id, :merchant_name, :name, :description, :price,
                :image_url, :quantity, :measurement_type, :unit, :quantity_value,
                :custom_unit, :item_type, :category, :delivery_fee,
                :min_order, :prep_time, :variant_id, :variant_data,
                :variant_name, :has_variants, :instructions,
                1, NOW(), NOW()
            )"
        );
        
        $insertStmt->execute([
            ':cart_id' => $cart['id'],
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId,
            ':quick_order_item_id' => $quickOrderItemId,
            ':merchant_id' => $merchantId,
            ':merchant_name' => $quickOrder['merchant_name'],
            ':name' => $itemName,
            ':description' => $selectedItem['item_description'],
            ':price' => $finalPrice,
            ':image_url' => $selectedItem['item_image'],
            ':quantity' => $quantity,
            ':measurement_type' => $selectedItem['measurement_type'] ?? 'count', // CHANGED from 'quantity' to 'count'
            ':unit' => $selectedItem['unit'] ?? null,
            ':quantity_value' => $selectedItem['item_quantity'] ?? null,
            ':custom_unit' => $selectedItem['custom_unit'] ?? null,
            ':item_type' => $quickOrder['item_type'] ?? 'quick_order',
            ':category' => $quickOrder['category'] ?? '',
            ':delivery_fee' => $quickOrder['delivery_fee'] ?? 0,
            ':min_order' => $quickOrder['min_order_amount'] ?? 0,
            ':prep_time' => $quickOrder['merchant_prep_time'] ?? null,
            ':variant_id' => $variantId,
            ':variant_data' => $variantData ? json_encode($variantData) : null,
            ':variant_name' => $variantName,
            ':has_variants' => $selectedItem['has_variants'] ? 1 : 0,
            ':instructions' => $specialInstructions
        ]);
        
        $cartItemId = $conn->lastInsertId();
        
        // Add add-ons if any
        if (!empty($selectedAddOns)) {
            addCartItemAddOns($conn, $cartItemId, $selectedAddOns, $quantity);
        }
        
        $message = 'Quick order added to cart';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    // Get updated cart
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'message' => $message,
        'data' => [
            'cart_item_id' => $cartItemId,
            'cart_id' => $cart['id'],
            'cart_summary' => $totals
        ]
    ]);
}

/*********************************
 * ADD CART ITEM ADD-ONS
 *********************************/
function addCartItemAddOns($conn, $cartItemId, $addOns, $quantity) {
    foreach ($addOns as $addOn) {
        $addOnId = $addOn['id'] ?? null;
        $addOnName = $addOn['name'] ?? '';
        $addOnPrice = floatval($addOn['price'] ?? 0);
        $addOnQty = intval($addOn['quantity'] ?? 1);
        $addOnCategory = $addOn['category'] ?? null;
        
        $stmt = $conn->prepare(
            "INSERT INTO cart_addons (
                cart_item_id, addon_id, name, price, quantity, category, created_at
            ) VALUES (
                :cart_item_id, :addon_id, :name, :price, :quantity, :category, NOW()
            )"
        );
        
        $stmt->execute([
            ':cart_item_id' => $cartItemId,
            ':addon_id' => $addOnId,
            ':name' => $addOnName,
            ':price' => $addOnPrice,
            ':quantity' => $addOnQty * $quantity,
            ':category' => $addOnCategory
        ]);
    }
}

/*********************************
 * UPDATE CART ITEM QUANTITY
 *********************************/
function updateCartItemQuantity($conn, $data, $userId, $sessionId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 0);
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    if ($quantity < 0) {
        ResponseHandler::error('Quantity cannot be negative', 400);
    }
    
    // Get cart item
    $itemStmt = $conn->prepare(
        "SELECT ci.*, c.id as cart_id
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id"
    );
    $itemStmt->execute([':id' => $cartItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Verify ownership
    if ($item['user_id'] && $item['user_id'] != $userId) {
        ResponseHandler::error('Unauthorized', 403);
    }
    
    if ($quantity == 0) {
        // Remove item
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items SET is_active = 0, updated_at = NOW() WHERE id = :id"
        );
        $deleteStmt->execute([':id' => $cartItemId]);
        
        // Remove add-ons
        $deleteAddOns = $conn->prepare("DELETE FROM cart_addons WHERE cart_item_id = :item_id");
        $deleteAddOns->execute([':item_id' => $cartItemId]);
        
        $message = 'Item removed from cart';
    } else {
        // Update quantity
        $updateStmt = $conn->prepare(
            "UPDATE cart_items SET quantity = :quantity, updated_at = NOW() WHERE id = :id"
        );
        $updateStmt->execute([
            ':quantity' => $quantity,
            ':id' => $cartItemId
        ]);
        
        $message = 'Quantity updated';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $item['cart_id']]);
    
    // Get updated cart
    $cartItems = getCartItemsByCartId($conn, $item['cart_id'], $baseUrl);
    $totals = calculateCartTotals($conn, $item['cart_id'], $cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'message' => $message,
        'data' => [
            'cart_summary' => $totals
        ]
    ]);
}

/*********************************
 * REMOVE CART ITEM
 *********************************/
function removeCartItem($conn, $data, $userId, $sessionId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item
    $itemStmt = $conn->prepare(
        "SELECT ci.*, c.id as cart_id
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id"
    );
    $itemStmt->execute([':id' => $cartItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Verify ownership
    if ($item['user_id'] && $item['user_id'] != $userId) {
        ResponseHandler::error('Unauthorized', 403);
    }
    
    // Soft delete item
    $deleteStmt = $conn->prepare(
        "UPDATE cart_items SET is_active = 0, updated_at = NOW() WHERE id = :id"
    );
    $deleteStmt->execute([':id' => $cartItemId]);
    
    // Remove add-ons
    $deleteAddOns = $conn->prepare("DELETE FROM cart_addons WHERE cart_item_id = :item_id");
    $deleteAddOns->execute([':item_id' => $cartItemId]);
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $item['cart_id']]);
    
    // Get updated cart
    $cartItems = getCartItemsByCartId($conn, $item['cart_id'], $baseUrl);
    $totals = calculateCartTotals($conn, $item['cart_id'], $cartItems);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Item removed from cart',
        'data' => [
            'cart_summary' => $totals
        ]
    ]);
}

/*********************************
 * CLEAR CART
 *********************************/
function clearCart($conn, $data, $userId, $sessionId) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    // Get all cart items
    $itemsStmt = $conn->prepare("SELECT id FROM cart_items WHERE cart_id = :cart_id AND is_active = 1");
    $itemsStmt->execute([':cart_id' => $cart['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Delete add-ons for each item
    foreach ($items as $item) {
        $deleteAddOns = $conn->prepare("DELETE FROM cart_addons WHERE cart_item_id = :item_id");
        $deleteAddOns->execute([':item_id' => $item['id']]);
    }
    
    // Soft delete all items
    $clearStmt = $conn->prepare(
        "UPDATE cart_items SET is_active = 0, updated_at = NOW() WHERE cart_id = :cart_id"
    );
    $clearStmt->execute([':cart_id' => $cart['id']]);
    $itemsCleared = $clearStmt->rowCount();
    
    // Remove promotion
    $deletePromo = $conn->prepare("DELETE FROM cart_promotions WHERE cart_id = :cart_id");
    $deletePromo->execute([':cart_id' => $cart['id']]);
    
    // Update cart
    $updateCartStmt = $conn->prepare(
        "UPDATE carts SET updated_at = NOW() WHERE id = :cart_id"
    );
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Cart cleared successfully',
        'data' => [
            'items_cleared' => $itemsCleared,
            'cart_id' => $cart['id']
        ]
    ]);
}

/*********************************
 * APPLY PROMOTION TO CART - FIXED
 *********************************/
function applyPromotionToCart($conn, $data, $userId, $sessionId) {
    $promotionId = $data['promotion_id'] ?? null;
    $promoCode = $data['promo_code'] ?? null;
    
    // For now, we'll only support promotion by ID since 'code' column doesn't exist
    if (!$promotionId) {
        ResponseHandler::error('Promotion ID is required (promo codes not supported yet)', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    // Get promotion by ID only
    $promoStmt = $conn->prepare("SELECT * FROM promotions WHERE id = :id AND is_active = 1");
    $promoStmt->execute([':id' => $promotionId]);
    $promotion = $promoStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promotion) {
        ResponseHandler::error('Invalid promotion', 404);
    }
    
    // Check if promotion already applied
    $checkStmt = $conn->prepare(
        "SELECT id FROM cart_promotions WHERE cart_id = :cart_id AND promotion_id = :promotion_id"
    );
    $checkStmt->execute([
        ':cart_id' => $cart['id'],
        ':promotion_id' => $promotion['id']
    ]);
    
    if ($checkStmt->fetch()) {
        ResponseHandler::error('Promotion already applied to this cart', 400);
    }
    
    // Calculate discount amount
    $cartItems = getCartItemsByCartId($conn, $cart['id'], '');
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += $item['total'] ?? 0;
    }
    
    $discountAmount = 0;
    if ($promotion['discount_type'] === 'percentage') {
        $discountAmount = $subtotal * ($promotion['discount_value'] / 100);
        if (!empty($promotion['max_discount_amount']) && $discountAmount > $promotion['max_discount_amount']) {
            $discountAmount = $promotion['max_discount_amount'];
        }
    } elseif ($promotion['discount_type'] === 'fixed') {
        $discountAmount = min($promotion['discount_value'], $subtotal);
    }
    
    // Apply promotion
    $insertStmt = $conn->prepare(
        "INSERT INTO cart_promotions (cart_id, promotion_id, discount_amount, applied_at)
         VALUES (:cart_id, :promotion_id, :discount_amount, NOW())"
    );
    
    $insertStmt->execute([
        ':cart_id' => $cart['id'],
        ':promotion_id' => $promotion['id'],
        ':discount_amount' => $discountAmount
    ]);
    
    // Update cart
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Promotion applied successfully',
        'data' => [
            'promotion' => [
                'id' => $promotion['id'],
                'name' => $promotion['title'], // Using title instead of code/name
                'description' => $promotion['description'],
                'discount_type' => $promotion['discount_type'],
                'discount_value' => floatval($promotion['discount_value']),
                'discount_amount' => $discountAmount
            ]
        ]
    ]);
}

/*********************************
 * REMOVE PROMOTION FROM CART
 *********************************/
function removePromotionFromCart($conn, $data, $userId, $sessionId) {
    $cart = getOrCreateUserCart($conn, $userId, $sessionId);
    
    $deleteStmt = $conn->prepare("DELETE FROM cart_promotions WHERE cart_id = :cart_id");
    $deleteStmt->execute([':cart_id' => $cart['id']]);
    
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    ResponseHandler::success([
        'success' => true,
        'message' => 'Promotion removed successfully'
    ]);
}

/*********************************
 * TOGGLE SAVE FOR LATER
 *********************************/
function toggleSaveForLater($conn, $data, $userId, $sessionId) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $save = $data['save'] ?? true;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item
    $itemStmt = $conn->prepare(
        "SELECT ci.* FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id"
    );
    $itemStmt->execute([':id' => $cartItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Verify ownership
    if ($item['user_id'] && $item['user_id'] != $userId) {
        ResponseHandler::error('Unauthorized', 403);
    }
    
    $updateStmt = $conn->prepare(
        "UPDATE cart_items SET is_saved_for_later = :save, updated_at = NOW() WHERE id = :id"
    );
    $updateStmt->execute([
        ':save' => $save ? 1 : 0,
        ':id' => $cartItemId
    ]);
    
    $message = $save ? 'Item saved for later' : 'Item moved back to cart';
    
    ResponseHandler::success([
        'success' => true,
        'message' => $message
    ]);
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = checkAuthentication($conn);
    $sessionId = session_id();
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'update_item':
            updateCartItemQuantity($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        default:
            ResponseHandler::error('Invalid action for PUT request', 400);
    }
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = checkAuthentication($conn);
    $sessionId = session_id();
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'remove_item':
            removeCartItem($conn, $data, $userId, $sessionId, $baseUrl);
            break;
        case 'clear_cart':
            clearCart($conn, $data, $userId, $sessionId);
            break;
        default:
            ResponseHandler::error('Invalid action for DELETE request', 400);
    }
}

/*********************************
 * FORMAT CART ITEM DATA
 *********************************/
function formatCartItemData($item, $baseUrl) {
    // Format images
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . ltrim($item['image_url'], '/');
        }
    } elseif (!empty($item['menu_item_image'])) {
        $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($item['menu_item_image'], '/');
    } elseif (!empty($item['quick_order_image'])) {
        $imageUrl = rtrim($baseUrl, '/') . '/uploads/quick_orders/' . ltrim($item['quick_order_image'], '/');
    } elseif (!empty($item['quick_order_item_image'])) {
        $imageUrl = rtrim($baseUrl, '/') . '/uploads/quick_order_items/' . ltrim($item['quick_order_item_image'], '/');
    }
    
    $merchantImage = '';
    if (!empty($item['merchant_image'])) {
        if (strpos($item['merchant_image'], 'http') === 0) {
            $merchantImage = $item['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/merchants/' . ltrim($item['merchant_image'], '/');
        }
    }
    
    $price = floatval($item['price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    $total = $price * $quantity;
    
    // Calculate add-ons total
    $addOnsTotal = 0;
    if (!empty($item['add_ons'])) {
        foreach ($item['add_ons'] as $addOn) {
            $addOnsTotal += floatval($addOn['price'] ?? 0) * intval($addOn['quantity'] ?? 1);
        }
    }
    
    // Determine item name based on source
    $itemName = $item['name'] ?? '';
    if (empty($itemName)) {
        if ($item['source_type'] === 'menu_item') {
            $itemName = $item['menu_item_name'] ?? '';
        } else {
            $itemName = $item['quick_order_item_name'] ?? $item['quick_order_title'] ?? '';
        }
    }
    
    // Parse selected options
    $selectedOptions = null;
    if (!empty($item['selected_options'])) {
        if (is_string($item['selected_options'])) {
            $selectedOptions = json_decode($item['selected_options'], true);
        } else {
            $selectedOptions = $item['selected_options'];
        }
    }
    
    // Parse variant data
    $variantData = null;
    if (!empty($item['variant_data'])) {
        if (is_string($item['variant_data'])) {
            $variantData = json_decode($item['variant_data'], true);
        } else {
            $variantData = $item['variant_data'];
        }
    }
    
    return [
        'id' => intval($item['id'] ?? 0),
        'cart_id' => intval($item['cart_id'] ?? 0),
        'source_type' => $item['source_type'] ?? 'menu_item',
        
        // Basic info
        'name' => $itemName,
        'description' => $item['description'] ?? $item['menu_item_description'] ?? $item['quick_order_item_description'] ?? '',
        'price' => $price,
        'quantity' => $quantity,
        'total' => $total,
        'add_ons_total' => $addOnsTotal,
        'grand_total' => $total + $addOnsTotal,
        'formatted_price' => 'MK ' . number_format($price, 2),
        'formatted_total' => 'MK ' . number_format($total + $addOnsTotal, 2),
        
        // Images
        'image_url' => $imageUrl,
        
        // Category & Type
        'category' => $item['category'] ?? $item['menu_item_category'] ?? $item['quick_order_category'] ?? '',
        'item_type' => $item['item_type'] ?? 'food',
        
        // Unit info
        'measurement_type' => $item['measurement_type'] ?? 'count',
        'unit' => $item['unit'] ?? null,
        'quantity_value' => $item['quantity_value'] ?? $item['item_quantity_value'] ?? null,
        'custom_unit' => $item['custom_unit'] ?? null,
        
        // Variants
        'has_variants' => boolval($item['has_variants'] ?? false),
        'variant_id' => $item['variant_id'] ?? null,
        'variant_data' => $variantData,
        'variant_name' => $item['variant_name'] ?? '',
        'selected_options' => $selectedOptions,
        
        // Add-ons
        'add_ons' => array_map(function($addOn) {
            return [
                'id' => intval($addOn['id'] ?? 0),
                'addon_id' => intval($addOn['addon_id'] ?? 0),
                'name' => $addOn['name'] ?? '',
                'price' => floatval($addOn['price'] ?? 0),
                'quantity' => intval($addOn['quantity'] ?? 1),
                'category' => $addOn['category'] ?? null,
                'total' => floatval($addOn['price'] ?? 0) * intval($addOn['quantity'] ?? 1)
            ];
        }, $item['add_ons'] ?? []),
        
        // Special instructions
        'special_instructions' => $item['special_instructions'] ?? '',
        
        // Merchant info
        'merchant_id' => intval($item['merchant_id'] ?? 0),
        'merchant_name' => $item['merchant_name'] ?? 'Unknown Merchant',
        'merchant_image' => $merchantImage,
        'merchant_rating' => floatval($item['merchant_rating'] ?? 0),
        'merchant_is_open' => boolval($item['merchant_is_open'] ?? true),
        'business_type' => $item['business_type'] ?? 'restaurant',
        'cuisine_types' => !empty($item['cuisine_type']) ? (is_string($item['cuisine_type']) ? json_decode($item['cuisine_type'], true) : $item['cuisine_type']) : [],
        'merchant_address' => $item['merchant_address'] ?? '',
        'latitude' => floatval($item['latitude'] ?? 0),
        'longitude' => floatval($item['longitude'] ?? 0),
        
        // Delivery info
        'delivery_fee' => floatval($item['merchant_delivery_fee'] ?? $item['delivery_fee'] ?? 0),
        'min_order' => floatval($item['merchant_min_order'] ?? $item['min_order_amount'] ?? 0),
        'free_delivery_threshold' => floatval($item['free_delivery_threshold'] ?? 0),
        'delivery_time' => $item['delivery_time'] ?? '30-45 min',
        'preparation_time' => $item['preparation_time'] ?? $item['merchant_preparation_time'] ?? '15-20 min',
        
        // Quick order specific
        'quick_order_id' => $item['quick_order_id'] ? intval($item['quick_order_id']) : null,
        'quick_order_item_id' => $item['quick_order_item_id'] ? intval($item['quick_order_item_id']) : null,
        'custom_price' => isset($item['custom_price']) ? floatval($item['custom_price']) : null,
        
        // Flags
        'is_saved_for_later' => boolval($item['is_saved_for_later'] ?? false),
        'is_active' => boolval($item['is_active'] ?? true),
        
        // Timestamps
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}
?>