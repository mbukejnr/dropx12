<?php
/*********************************
 * CHECKOUT SCREEN
 * Payments handled by mobile app natively
 * Just creates order and returns details
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIGURATION
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
 * CONSTANTS
 *********************************/
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');

// Payment methods the app supports
define('PAYMENT_METHOD_DROPX_WALLET', 'dropx_wallet');
define('PAYMENT_METHOD_AIRTEL_MONEY', 'airtel_money');
define('PAYMENT_METHOD_TNM_MPAMBA', 'tnm_mpamba');
define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');

/*********************************
 * AUTHENTICATION
 *********************************/
function authenticateUser($conn) {
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
    
    return false;
}

/*********************************
 * GET ACTIVE CART
 *********************************/
function getActiveCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, user_id, status, applied_promotion_id, applied_discount 
         FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * GET CART ITEMS WITH MERCHANT
 *********************************/
function getCartItemsWithMerchant($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.menu_item_id,
            ci.quantity,
            mi.name as item_name,
            mi.price,
            mi.merchant_id,
            m.id as merchant_id,
            m.name as merchant_name,
            m.delivery_fee as merchant_delivery_fee,
            m.minimum_order as merchant_minimum,
            m.bank_account_name,
            m.bank_account_number,
            m.bank_name
         FROM cart_items ci
         JOIN menu_items mi ON ci.menu_item_id = mi.id
         JOIN merchants m ON mi.merchant_id = m.id
         WHERE ci.cart_id = :cart_id AND ci.is_active = 1"
    );
    $stmt->execute([':cart_id' => $cartId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * CALCULATE CART TOTALS
 *********************************/
function calculateCartTotals($conn, $cartId, $userId, $items) {
    if (empty($items)) {
        return null;
    }
    
    // Get merchant info from first item
    $merchantId = $items[0]['merchant_id'];
    $merchantName = $items[0]['merchant_name'];
    $deliveryFee = floatval($items[0]['merchant_delivery_fee'] ?? 1500.00);
    $minimumOrder = floatval($items[0]['merchant_minimum'] ?? 0);
    $bankDetails = [
        'bank_name' => $items[0]['bank_name'] ?? null,
        'account_name' => $items[0]['bank_account_name'] ?? null,
        'account_number' => $items[0]['bank_account_number'] ?? null
    ];
    
    // Calculate subtotal
    $subtotal = 0;
    $itemCount = 0;
    $totalQuantity = 0;
    $formattedItems = [];
    
    foreach ($items as $item) {
        $itemTotal = floatval($item['price']) * intval($item['quantity']);
        $subtotal += $itemTotal;
        $itemCount++;
        $totalQuantity += intval($item['quantity']);
        
        $formattedItems[] = [
            'id' => $item['id'],
            'menu_item_id' => $item['menu_item_id'],
            'name' => $item['item_name'],
            'quantity' => intval($item['quantity']),
            'price' => floatval($item['price']),
            'total' => $itemTotal,
            'formatted_price' => 'MK' . number_format($item['price'], 2),
            'formatted_total' => 'MK' . number_format($itemTotal, 2)
        ];
    }
    
    // Check minimum order
    $minimumMet = true;
    $shortfall = 0;
    if ($minimumOrder > 0 && $subtotal < $minimumOrder) {
        $minimumMet = false;
        $shortfall = $minimumOrder - $subtotal;
    }
    
    // Get cart discount
    $cartStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $cartStmt->execute([':cart_id' => $cartId]);
    $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);
    $discount = floatval($cartData['applied_discount'] ?? 0);
    
    // Apply discount
    $adjustedSubtotal = max(0, $subtotal - $discount);
    
    // Calculate fees
    $serviceFee = max(500.00, $adjustedSubtotal * 0.02);
    $taxRate = 0.165;
    
    // Calculate tax
    $taxableAmount = $adjustedSubtotal + $deliveryFee + $serviceFee;
    $taxAmount = round($taxableAmount * $taxRate, 2);
    
    // Total
    $totalAmount = $taxableAmount + $taxAmount;
    
    return [
        'merchant' => [
            'id' => $merchantId,
            'name' => $merchantName,
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => 'MK' . number_format($deliveryFee, 2),
            'minimum_order' => $minimumOrder,
            'minimum_order_formatted' => 'MK' . number_format($minimumOrder, 2),
            'minimum_met' => $minimumMet,
            'shortfall' => $shortfall,
            'shortfall_formatted' => 'MK' . number_format($shortfall, 2),
            'bank_details' => $bankDetails
        ],
        'items' => $formattedItems,
        'subtotal' => round($subtotal, 2),
        'subtotal_formatted' => 'MK' . number_format($subtotal, 2),
        'discount' => round($discount, 2),
        'discount_formatted' => 'MK' . number_format($discount, 2),
        'adjusted_subtotal' => round($adjustedSubtotal, 2),
        'adjusted_subtotal_formatted' => 'MK' . number_format($adjustedSubtotal, 2),
        'delivery_fee' => $deliveryFee,
        'delivery_fee_formatted' => 'MK' . number_format($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'service_fee_formatted' => 'MK' . number_format($serviceFee, 2),
        'tax_amount' => $taxAmount,
        'tax_amount_formatted' => 'MK' . number_format($taxAmount, 2),
        'total_amount' => round($totalAmount, 2),
        'total_amount_formatted' => 'MK' . number_format($totalAmount, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity,
        'currency' => 'MWK'
    ];
}

/*********************************
 * GET USER DEFAULT ADDRESS
 *********************************/
function getUserDefaultAddress($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, label, address_line1, city, neighborhood, latitude, longitude, phone 
         FROM addresses 
         WHERE user_id = :user_id AND is_default = 1 LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * CREATE ORDER
 *********************************/
function createOrder($conn, $userId, $cartId, $totals, $address) {
    // Generate order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Create order with pending payment status
        $stmt = $conn->prepare(
            "INSERT INTO orders 
                (order_number, user_id, merchant_id, 
                 subtotal, discount_amount, delivery_fee, service_fee, tax_amount, total_amount,
                 delivery_address, delivery_phone, payment_status, status, created_at, updated_at)
             VALUES 
                (:order_number, :user_id, :merchant_id,
                 :subtotal, :discount, :delivery_fee, :service_fee, :tax_amount, :total_amount,
                 :delivery_address, :delivery_phone, 'pending', 'pending_payment', NOW(), NOW())"
        );
        
        $deliveryAddress = $address ? $address['address_line1'] . ', ' . $address['city'] : 'Address not set';
        $deliveryPhone = $address['phone'] ?? '';
        
        $params = [
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $totals['merchant']['id'],
            ':subtotal' => $totals['subtotal'],
            ':discount' => $totals['discount'],
            ':delivery_fee' => $totals['delivery_fee'],
            ':service_fee' => $totals['service_fee'],
            ':tax_amount' => $totals['tax_amount'],
            ':total_amount' => $totals['total_amount'],
            ':delivery_address' => $deliveryAddress,
            ':delivery_phone' => $deliveryPhone
        ];
        
        $stmt->execute($params);
        $orderId = $conn->lastInsertId();
        
        // Add order items
        $itemStmt = $conn->prepare(
            "INSERT INTO order_items 
                (order_id, menu_item_id, item_name, quantity, unit_price, total_price)
             VALUES 
                (:order_id, :menu_item_id, :item_name, :quantity, :unit_price, :total_price)"
        );
        
        foreach ($totals['items'] as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':menu_item_id' => $item['menu_item_id'],
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['price'],
                ':total_price' => $item['total']
            ]);
        }
        
        // Create tracking
        $trackStmt = $conn->prepare(
            "INSERT INTO order_tracking (order_id, status, estimated_delivery)
             VALUES (:order_id, 'Order placed', :estimated)"
        );
        $trackStmt->execute([
            ':order_id' => $orderId,
            ':estimated' => date('Y-m-d H:i:s', strtotime('+45 minutes'))
        ]);
        
        // Clear cart
        $clearStmt = $conn->prepare("UPDATE cart_items SET is_active = 0 WHERE cart_id = :cart_id");
        $clearStmt->execute([':cart_id' => $cartId]);
        
        $updateCartStmt = $conn->prepare("UPDATE carts SET status = 'checkout' WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cartId]);
        
        $conn->commit();
        
        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/*********************************
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Connect to database
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Authenticate user
    $userId = authenticateUser($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    /*********************************
     * GET CHECKOUT DATA
     *********************************/
    if ($method === 'GET') {
        // Get active cart
        $cart = getActiveCart($conn, $userId);
        if (!$cart) {
            ResponseHandler::error('No active cart found', 404);
        }
        
        // Get cart items
        $items = getCartItemsWithMerchant($conn, $cart['id']);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Check if all items are from same merchant
        $merchantIds = array_unique(array_column($items, 'merchant_id'));
        if (count($merchantIds) > 1) {
            ResponseHandler::error('Cart contains items from multiple merchants', 400);
        }
        
        // Calculate totals
        $totals = calculateCartTotals($conn, $cart['id'], $userId, $items);
        
        // Check minimum order
        if (!$totals['merchant']['minimum_met']) {
            ResponseHandler::error([
                'message' => 'Minimum order requirement not met',
                'shortfall' => $totals['merchant']['shortfall'],
                'shortfall_formatted' => $totals['merchant']['shortfall_formatted']
            ], 400);
        }
        
        // Get user address
        $address = getUserDefaultAddress($conn, $userId);
        
        // Return checkout data
        ResponseHandler::success([
            'cart_id' => $cart['id'],
            'order_data' => [
                'merchant' => $totals['merchant'],
                'items' => $totals['items'],
                'totals' => [
                    'subtotal' => $totals['subtotal'],
                    'subtotal_formatted' => $totals['subtotal_formatted'],
                    'discount' => $totals['discount'],
                    'discount_formatted' => $totals['discount_formatted'],
                    'delivery_fee' => $totals['delivery_fee'],
                    'delivery_fee_formatted' => $totals['delivery_fee_formatted'],
                    'service_fee' => $totals['service_fee'],
                    'service_fee_formatted' => $totals['service_fee_formatted'],
                    'tax_amount' => $totals['tax_amount'],
                    'tax_amount_formatted' => $totals['tax_amount_formatted'],
                    'total_amount' => $totals['total_amount'],
                    'total_amount_formatted' => $totals['total_amount_formatted']
                ]
            ],
            'delivery' => [
                'address' => $address ? [
                    'id' => $address['id'],
                    'label' => $address['label'] ?? 'Home',
                    'address_line1' => $address['address_line1'],
                    'city' => $address['city'],
                    'neighborhood' => $address['neighborhood'] ?? '',
                    'phone' => $address['phone'] ?? '',
                    'latitude' => $address['latitude'] ?? null,
                    'longitude' => $address['longitude'] ?? null
                ] : null
            ],
            'payment_methods' => [
                [
                    'id' => PAYMENT_METHOD_DROPX_WALLET,
                    'name' => 'DropxWallet',
                    'type' => 'wallet',
                    'icon' => 'wallet',
                    'description' => 'Pay using your DropxWallet balance'
                ],
                [
                    'id' => PAYMENT_METHOD_AIRTEL_MONEY,
                    'name' => 'Airtel Money',
                    'type' => 'mobile_money',
                    'icon' => 'airtel',
                    'description' => 'Pay using Airtel Money',
                    'provider' => 'Airtel Malawi',
                    'min_amount' => 100,
                    'max_amount' => 1000000
                ],
                [
                    'id' => PAYMENT_METHOD_TNM_MPAMBA,
                    'name' => 'TNM Mpamba',
                    'type' => 'mobile_money',
                    'icon' => 'tnm',
                    'description' => 'Pay using TNM Mpamba',
                    'provider' => 'TNM',
                    'min_amount' => 100,
                    'max_amount' => 1000000
                ],
                [
                    'id' => PAYMENT_METHOD_BANK_TRANSFER,
                    'name' => 'Bank Transfer',
                    'type' => 'bank',
                    'icon' => 'bank',
                    'description' => 'Pay via bank transfer',
                    'min_amount' => 1000,
                    'max_amount' => 10000000,
                    'merchant_bank' => $totals['merchant']['bank_details']
                ]
            ]
        ]);
    }
    
    /*********************************
     * CREATE ORDER (PRE-PAYMENT)
     *********************************/
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Get cart
        $cartId = $input['cart_id'] ?? null;
        if (!$cartId) {
            $cart = getActiveCart($conn, $userId);
            $cartId = $cart['id'] ?? null;
        }
        
        if (!$cartId) {
            ResponseHandler::error('Cart not found', 404);
        }
        
        // Get cart items
        $items = getCartItemsWithMerchant($conn, $cartId);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Calculate totals
        $totals = calculateCartTotals($conn, $cartId, $userId, $items);
        
        // Get address
        $address = getUserDefaultAddress($conn, $userId);
        if (!$address) {
            ResponseHandler::error('Please set a delivery address first', 400);
        }
        
        // Create order
        $order = createOrder($conn, $userId, $cartId, $totals, $address);
        
        ResponseHandler::success([
            'order_id' => $order['order_id'],
            'order_number' => $order['order_number'],
            'amount' => $totals['total_amount'],
            'amount_formatted' => $totals['total_amount_formatted'],
            'merchant' => [
                'id' => $totals['merchant']['id'],
                'name' => $totals['merchant']['name']
            ],
            'payment_methods' => [
                PAYMENT_METHOD_DROPX_WALLET,
                PAYMENT_METHOD_AIRTEL_MONEY,
                PAYMENT_METHOD_TNM_MPAMBA,
                PAYMENT_METHOD_BANK_TRANSFER
            ]
        ], 'Order created successfully');
    }
    
    /*********************************
     * UPDATE ORDER PAYMENT STATUS
     *********************************/
    elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        if ($action === 'payment_status') {
            $orderId = $input['order_id'] ?? null;
            $paymentMethod = $input['payment_method'] ?? null;
            $paymentStatus = $input['payment_status'] ?? 'paid'; // paid or failed
            
            if (!$orderId || !$paymentMethod) {
                ResponseHandler::error('Order ID and payment method required', 400);
            }
            
            // Verify order belongs to user
            $checkStmt = $conn->prepare("SELECT id FROM orders WHERE id = :id AND user_id = :user_id");
            $checkStmt->execute([':id' => $orderId, ':user_id' => $userId]);
            
            if (!$checkStmt->fetch()) {
                ResponseHandler::error('Order not found', 404);
            }
            
            if ($paymentStatus === 'paid') {
                // Update order to paid
                $stmt = $conn->prepare(
                    "UPDATE orders 
                     SET payment_method = :method, payment_status = 'paid', status = 'confirmed', updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':method' => $paymentMethod,
                    ':id' => $orderId
                ]);
                
                ResponseHandler::success([
                    'order_id' => $orderId,
                    'payment_method' => $paymentMethod,
                    'status' => 'confirmed'
                ], 'Payment confirmed');
                
            } else {
                // Payment failed - cancel order
                $stmt = $conn->prepare(
                    "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :id"
                );
                $stmt->execute([':id' => $orderId]);
                
                ResponseHandler::success([
                    'order_id' => $orderId,
                    'status' => 'cancelled'
                ], 'Order cancelled');
            }
        }
    }
    
    else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Checkout error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>