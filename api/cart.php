<?php
/*********************************
 * CART API - LOGGED IN USERS ONLY
 * Simplified - Focus on items only
 * Cart ID is used by orders.php for checkout
 *********************************/

ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'Undefined array key') !== false) {
        return true;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/*********************************
 * SESSION CONFIG
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

$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION - LOGGED IN ONLY
 *********************************/
function checkAuthentication() {
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId) {
        return $userId;
    }
    
    return null;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';
    $userId = checkAuthentication();
    
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
    }

    if ($method === 'GET') {
        if (!empty($action)) {
            handleGetActions($action, $input, $userId);
        } else {
            handleGetRequest($userId);
        }
    } elseif ($method === 'POST') {
        if (!empty($action)) {
            handlePostActions($action, $input, $userId);
        } else {
            handlePostRequest($userId);
        }
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } elseif ($method === 'DELETE') {
        handleDeleteRequest($userId);
    } else {
        ob_clean();
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET ACTION HANDLERS
 *********************************/
function handleGetActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'get_cart':
                getCurrentCart($conn, $userId);
                break;
            case 'count':
                getCartItemCount($conn, $userId);
                break;
            case 'summary':
                getCartSummary($conn, $userId);
                break;
            case 'items':
                getCartItems($conn, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST ACTION HANDLERS
 *********************************/
function handlePostActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'add_item':
                addItemToCart($conn, $input, $userId);
                break;
            case 'add_quick_order':
                addQuickOrderToCart($conn, $input, $userId);
                break;
            case 'update_quantity':
                updateCartItemQuantity($conn, $input, $userId);
                break;
            case 'remove_item':
                removeCartItem($conn, $input, $userId);
                break;
            case 'clear_cart':
                clearCart($conn, $input, $userId);
                break;
            case 'save_for_later':
                toggleSaveForLater($conn, $input, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * LEGACY HANDLERS
 *********************************/
function handleGetRequest($userId) {
    getCurrentCart($userId);
}

function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'add_item':
                addItemToCart($conn, $input, $userId);
                break;
            case 'add_quick_order':
                addQuickOrderToCart($conn, $input, $userId);
                break;
            case 'update_quantity':
                updateCartItemQuantity($conn, $input, $userId);
                break;
            case 'remove_item':
                removeCartItem($conn, $input, $userId);
                break;
            case 'clear_cart':
                clearCart($conn, $input, $userId);
                break;
            case 'save_for_later':
                toggleSaveForLater($conn, $input, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        updateCartItemQuantity($conn, $input, $userId);
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

function handleDeleteRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        $action = $input['action'] ?? '';
        
        if ($action === 'clear_cart') {
            clearCart($conn, $input, $userId);
        } else {
            removeCartItem($conn, $input, $userId);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CART DATABASE FUNCTIONS
 *********************************/

/**
 * Get user's active cart
 */
function getUserActiveCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id FROM carts 
         WHERE user_id = :user_id 
         AND status = 'active'
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Create new cart for user
 */
function createUserCart($conn, $userId) {
    $insertStmt = $conn->prepare(
        "INSERT INTO carts (user_id, status, created_at, updated_at)
         VALUES (:user_id, 'active', NOW(), NOW())"
    );
    
    $insertStmt->execute([':user_id' => $userId]);
    $cartId = $conn->lastInsertId();
    
    return ['id' => $cartId];
}

/**
 * Get or create user cart
 */
function getOrCreateUserCart($conn, $userId) {
    $cart = getUserActiveCart($conn, $userId);
    
    if ($cart) {
        return $cart;
    }
    
    return createUserCart($conn, $userId);
}

/*********************************
 * GET CURRENT CART - SIMPLIFIED
 *********************************/
function getCurrentCart($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::error('Failed to retrieve cart', 500);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cart['id']);
    
    // Calculate simple totals
    $subtotal = 0;
    $itemCount = 0;
    $totalQuantity = 0;
    
    foreach ($cartItems as $item) {
        $subtotal += $item['grand_total'];
        $itemCount++;
        $totalQuantity += $item['quantity'];
    }
    
    ResponseHandler::success([
        'cart_id' => $cart['id'],
        'items' => $cartItems,
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'item_count' => $itemCount,
            'total_quantity' => $totalQuantity
        ],
        'has_items' => !empty($cartItems)
    ]);
}

/*********************************
 * GET CART ITEMS - SIMPLIFIED
 *********************************/
function getCartItems($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    $cartItems = getCartItemsByCartId($conn, $cart['id']);
    
    ResponseHandler::success([
        'items' => $cartItems,
        'count' => count($cartItems)
    ]);
}

/*********************************
 * GET CART SUMMARY - SIMPLIFIED
 *********************************/
function getCartSummary($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(id) as item_count,
            SUM(quantity) as total_quantity,
            SUM(price * quantity) as subtotal
         FROM cart_items 
         WHERE cart_id = :cart_id 
         AND is_active = 1 
         AND is_saved_for_later = 0"
    );
    
    $stmt->execute([':cart_id' => $cart['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'item_count' => intval($result['item_count'] ?? 0),
        'total_quantity' => intval($result['total_quantity'] ?? 0),
        'subtotal' => floatval($result['subtotal'] ?? 0),
        'has_items' => ($result['item_count'] ?? 0) > 0,
        'cart_id' => $cart['id']
    ]);
}

/*********************************
 * GET CART ITEM COUNT - SIMPLIFIED
 *********************************/
function getCartItemCount($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(id) as item_count, 
            SUM(quantity) as total_quantity
         FROM cart_items 
         WHERE cart_id = :cart_id 
         AND is_active = 1 
         AND is_saved_for_later = 0"
    );
    
    $stmt->execute([':cart_id' => $cart['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'item_count' => intval($result['item_count'] ?? 0),
        'total_quantity' => intval($result['total_quantity'] ?? 0),
        'cart_id' => $cart['id']
    ]);
}

/*********************************
 * GET CART ITEMS BY CART ID - SIMPLIFIED
 *********************************/
function getCartItemsByCartId($conn, $cartId) {
    global $baseUrl;
    
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.cart_id,
            ci.name,
            ci.description,
            ci.price,
            ci.quantity,
            ci.total,
            ci.add_ons_total,
            ci.grand_total,
            ci.image_url,
            ci.merchant_id,
            ci.merchant_name,
            ci.variant_name,
            ci.special_instructions,
            ci.source_type
        FROM cart_items ci
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1
        AND ci.is_saved_for_later = 0
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get add-ons for each item
    foreach ($items as &$item) {
        $item['add_ons'] = getCartItemAddOns($conn, $item['id']);
        $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl);
    }
    
    return $items;
}

/*********************************
 * GET CART ITEM ADD-ONS
 *********************************/
function getCartItemAddOns($conn, $cartItemId) {
    $stmt = $conn->prepare(
        "SELECT id, name, price, quantity FROM cart_addons 
         WHERE cart_item_id = :cart_item_id
         ORDER BY created_at ASC"
    );
    $stmt->execute([':cart_item_id' => $cartItemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * ADD ITEM TO CART - SIMPLIFIED
 *********************************/
function addItemToCart($conn, $data, $userId) {
    global $baseUrl;
    
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
    
    // Get item details
    $itemStmt = $conn->prepare(
        "SELECT 
            mi.*,
            m.id as merchant_id,
            m.name as merchant_name
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
    $cart = getOrCreateUserCart($conn, $userId);
    
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
                 total = price * :quantity,
                 grand_total = (price * :quantity) + add_ons_total,
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
        $message = 'Item quantity updated';
    } else {
        $price = floatval($item['price']);
        $total = $price * $quantity;
        
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items (
                cart_id, user_id, menu_item_id, merchant_id, merchant_name,
                name, description, price, image_url, quantity,
                total, add_ons_total, grand_total,
                measurement_type, unit, item_type, category,
                special_instructions, selected_options, source_type,
                is_active, created_at, updated_at
            ) VALUES (
                :cart_id, :user_id, :menu_item_id, :merchant_id, :merchant_name,
                :name, :description, :price, :image_url, :quantity,
                :total, 0, :total,
                :measurement_type, :unit, :item_type, :category,
                :instructions, :selected_options, 'menu_item',
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
            ':price' => $price,
            ':image_url' => $item['image_url'],
            ':quantity' => $quantity,
            ':total' => $total,
            ':measurement_type' => $item['measurement_type'] ?? 'count',
            ':unit' => $item['unit'] ?? null,
            ':item_type' => $item['item_type'] ?? 'food',
            ':category' => $item['category'] ?? '',
            ':instructions' => $specialInstructions,
            ':selected_options' => $selectedOptions ? json_encode($selectedOptions) : null
        ]);
        
        $cartItemId = $conn->lastInsertId();
        $message = 'Item added to cart';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    // Get updated cart summary
    $cartItems = getCartItemsByCartId($conn, $cart['id']);
    $subtotal = 0;
    foreach ($cartItems as $cartItem) {
        $subtotal += $cartItem['grand_total'];
    }
    
    ResponseHandler::success([
        'message' => $message,
        'cart_item_id' => $cartItemId,
        'cart_id' => $cart['id'],
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'item_count' => count($cartItems),
            'total_quantity' => array_sum(array_column($cartItems, 'quantity'))
        ]
    ]);
}

/*********************************
 * ADD QUICK ORDER TO CART - SIMPLIFIED
 *********************************/
function addQuickOrderToCart($conn, $data, $userId) {
    global $baseUrl;
    
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
            qoi.id as item_id,
            qoi.name as item_name,
            qoi.description as item_description,
            qoi.price as item_price,
            qoi.image_url as item_image,
            qoi.measurement_type,
            qoi.unit,
            qoi.has_variants,
            qoi.variants_json,
            m.name as merchant_name
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
    
    $quickOrder = $items[0];
    
    // Find specific item
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
    $cart = getOrCreateUserCart($conn, $userId);
    
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
    
    $itemName = $selectedItem['item_name'] . $variantName;
    $total = $finalPrice * $quantity;
    
    if ($existingItem) {
        $newQuantity = $existingItem['quantity'] + $quantity;
        $newTotal = $finalPrice * $newQuantity;
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity,
                 total = price * :quantity,
                 grand_total = (price * :quantity) + add_ons_total,
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
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items (
                cart_id, user_id, quick_order_id, quick_order_item_id,
                merchant_id, merchant_name, name, description, price,
                image_url, quantity, total, grand_total,
                measurement_type, unit, item_type, category,
                variant_id, variant_data, variant_name, has_variants,
                special_instructions, source_type,
                is_active, created_at, updated_at
            ) VALUES (
                :cart_id, :user_id, :quick_order_id, :quick_order_item_id,
                :merchant_id, :merchant_name, :name, :description, :price,
                :image_url, :quantity, :total, :total,
                :measurement_type, :unit, :item_type, :category,
                :variant_id, :variant_data, :variant_name, :has_variants,
                :instructions, 'quick_order',
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
            ':total' => $total,
            ':measurement_type' => $selectedItem['measurement_type'] ?? 'count',
            ':unit' => $selectedItem['unit'] ?? null,
            ':item_type' => $quickOrder['item_type'] ?? 'quick_order',
            ':category' => $quickOrder['category'] ?? '',
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
    
    // Get updated cart summary
    $cartItems = getCartItemsByCartId($conn, $cart['id']);
    $subtotal = 0;
    foreach ($cartItems as $cartItem) {
        $subtotal += $cartItem['grand_total'];
    }
    
    ResponseHandler::success([
        'message' => $message,
        'cart_item_id' => $cartItemId,
        'cart_id' => $cart['id'],
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'item_count' => count($cartItems),
            'total_quantity' => array_sum(array_column($cartItems, 'quantity'))
        ]
    ]);
}

/*********************************
 * ADD CART ITEM ADD-ONS
 *********************************/
function addCartItemAddOns($conn, $cartItemId, $addOns, $quantity) {
    foreach ($addOns as $addOn) {
        $addOnName = $addOn['name'] ?? '';
        $addOnPrice = floatval($addOn['price'] ?? 0);
        $addOnQty = intval($addOn['quantity'] ?? 1);
        
        $stmt = $conn->prepare(
            "INSERT INTO cart_addons (
                cart_item_id, name, price, quantity, created_at
            ) VALUES (
                :cart_item_id, :name, :price, :quantity, NOW()
            )"
        );
        
        $stmt->execute([
            ':cart_item_id' => $cartItemId,
            ':name' => $addOnName,
            ':price' => $addOnPrice,
            ':quantity' => $addOnQty * $quantity
        ]);
    }
}

/*********************************
 * UPDATE CART ITEM QUANTITY
 *********************************/
function updateCartItemQuantity($conn, $data, $userId) {
    global $baseUrl;
    
    $cartItemId = $data['cart_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 0);
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    if ($quantity < 0) {
        ResponseHandler::error('Quantity cannot be negative', 400);
    }
    
    // Get cart item and verify ownership
    $itemStmt = $conn->prepare(
        "SELECT ci.*, c.id as cart_id
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id AND c.user_id = :user_id"
    );
    $itemStmt->execute([
        ':id' => $cartItemId,
        ':user_id' => $userId
    ]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
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
        $newTotal = floatval($item['price']) * $quantity;
        $newGrandTotal = $newTotal + floatval($item['add_ons_total']);
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity,
                 total = :total,
                 grand_total = :grand_total,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $updateStmt->execute([
            ':quantity' => $quantity,
            ':total' => $newTotal,
            ':grand_total' => $newGrandTotal,
            ':id' => $cartItemId
        ]);
        
        $message = 'Quantity updated';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
    $updateCartStmt->execute([':cart_id' => $item['cart_id']]);
    
    // Get updated cart summary
    $cartItems = getCartItemsByCartId($conn, $item['cart_id']);
    $subtotal = 0;
    foreach ($cartItems as $cartItem) {
        $subtotal += $cartItem['grand_total'];
    }
    
    ResponseHandler::success([
        'message' => $message,
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'item_count' => count($cartItems),
            'total_quantity' => array_sum(array_column($cartItems, 'quantity'))
        ]
    ]);
}

/*********************************
 * REMOVE CART ITEM
 *********************************/
function removeCartItem($conn, $data, $userId) {
    global $baseUrl;
    
    $cartItemId = $data['cart_item_id'] ?? null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item and verify ownership
    $itemStmt = $conn->prepare(
        "SELECT ci.*, c.id as cart_id
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id AND c.user_id = :user_id"
    );
    $itemStmt->execute([
        ':id' => $cartItemId,
        ':user_id' => $userId
    ]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
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
    
    // Get updated cart summary
    $cartItems = getCartItemsByCartId($conn, $item['cart_id']);
    $subtotal = 0;
    foreach ($cartItems as $cartItem) {
        $subtotal += $cartItem['grand_total'];
    }
    
    ResponseHandler::success([
        'message' => 'Item removed from cart',
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'item_count' => count($cartItems),
            'total_quantity' => array_sum(array_column($cartItems, 'quantity'))
        ]
    ]);
}

/*********************************
 * CLEAR CART
 *********************************/
function clearCart($conn, $data, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    // Get all cart items
    $itemsStmt = $conn->prepare(
        "SELECT id FROM cart_items 
         WHERE cart_id = :cart_id AND is_active = 1"
    );
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
    
    // Update cart
    $updateCartStmt = $conn->prepare(
        "UPDATE carts SET updated_at = NOW() WHERE id = :cart_id"
    );
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    ResponseHandler::success([
        'message' => 'Cart cleared successfully',
        'items_cleared' => $itemsCleared,
        'cart_id' => $cart['id']
    ]);
}

/*********************************
 * TOGGLE SAVE FOR LATER
 *********************************/
function toggleSaveForLater($conn, $data, $userId) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $save = $data['save'] ?? true;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item and verify ownership
    $itemStmt = $conn->prepare(
        "SELECT ci.* FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :id AND c.user_id = :user_id"
    );
    $itemStmt->execute([
        ':id' => $cartItemId,
        ':user_id' => $userId
    ]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
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
        'message' => $message,
        'is_saved_for_later' => $save
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/**
 * Format image URL
 */
function formatImageUrl($path, $baseUrl) {
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    return rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($path, '/');
}

/**
 * Format cart item data for response
 */
function formatCartItemData($item, $baseUrl) {
    return [
        'id' => intval($item['id']),
        'cart_id' => intval($item['cart_id']),
        'name' => $item['name'],
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price']),
        'quantity' => intval($item['quantity']),
        'total' => floatval($item['total']),
        'add_ons_total' => floatval($item['add_ons_total']),
        'grand_total' => floatval($item['grand_total']),
        'image_url' => formatImageUrl($item['image_url'], $baseUrl),
        'merchant_id' => intval($item['merchant_id']),
        'merchant_name' => $item['merchant_name'] ?? 'Restaurant',
        'variant_name' => $item['variant_name'] ?? null,
        'special_instructions' => $item['special_instructions'] ?? null,
        'source_type' => $item['source_type'] ?? 'menu_item',
        'add_ons' => array_map(function($addOn) {
            return [
                'id' => intval($addOn['id']),
                'name' => $addOn['name'],
                'price' => floatval($addOn['price']),
                'quantity' => intval($addOn['quantity']),
                'total' => floatval($addOn['price']) * intval($addOn['quantity'])
            ];
        }, $item['add_ons'] ?? [])
    ];
}
?>