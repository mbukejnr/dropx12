<?php
/*********************************
 * CHECKOUT SCREEN
 * Aggregator Model: Customer pays Dropx, Dropx pays merchants later
 * Malawi market - no tips, no tax, no service fees
 * Payment-first flow: Process payment before creating order
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Device-ID, X-Platform, X-App-Version, X-Timestamp");
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
if (!defined('CURRENCY')) {
    define('CURRENCY', 'MWK');
}
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', 'MK');
}

// Payment methods
if (!defined('PAYMENT_METHOD_DROPX_WALLET')) {
    define('PAYMENT_METHOD_DROPX_WALLET', 'dropx_wallet');
}
if (!defined('PAYMENT_METHOD_AIRTEL_MONEY')) {
    define('PAYMENT_METHOD_AIRTEL_MONEY', 'airtel_money');
}
if (!defined('PAYMENT_METHOD_TNM_MPAMBA')) {
    define('PAYMENT_METHOD_TNM_MPAMBA', 'tnm_mpamba');
}
if (!defined('PAYMENT_METHOD_BANK_TRANSFER')) {
    define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');
}

/*********************************
 * DROPX PAYMENT DETAILS
 *********************************/
define('DROPX_BANK_NAME', 'NBS Bank');
define('DROPX_BANK_ACCOUNT_NAME', 'DROPX LIMITED');
define('DROPX_BANK_ACCOUNT_NUMBER', '1234567890');
define('DROPX_AIRTEL_MONEY_NUMBER', '0999000000');
define('DROPX_TNM_MPAMBA_NUMBER', '0888000000');

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
            ci.name as item_name,
            ci.price,
            ci.merchant_id,
            ci.merchant_name,
            ci.merchant_delivery_fee,
            ci.merchant_min_order as merchant_minimum,
            ci.preparation_time,
            ci.variant_data,
            ci.add_ons,
            ci.selected_options,
            ci.special_instructions
         FROM cart_items ci
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
    $prepTime = $items[0]['preparation_time'] ?? 20;
    
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
            'formatted_total' => 'MK' . number_format($itemTotal, 2),
            'variant_data' => $item['variant_data'],
            'add_ons' => $item['add_ons'],
            'selected_options' => $item['selected_options'],
            'notes' => $item['special_instructions'] ?? ''
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
    
    // Total = subtotal + delivery fee - discount
    $totalAmount = ($subtotal + $deliveryFee) - $discount;
    
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
            'preparation_time' => $prepTime
        ],
        'items' => $formattedItems,
        'subtotal' => round($subtotal, 2),
        'subtotal_formatted' => 'MK' . number_format($subtotal, 2),
        'discount' => round($discount, 2),
        'discount_formatted' => 'MK' . number_format($discount, 2),
        'delivery_fee' => $deliveryFee,
        'delivery_fee_formatted' => 'MK' . number_format($deliveryFee, 2),
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
 * PROCESS PAYMENT - NEW FUNCTION
 *********************************/
function processPayment($conn, $userId, $cartId, $paymentMethod, $paymentDetails) {
    try {
        // Get cart items to calculate total
        $items = getCartItemsWithMerchant($conn, $cartId);
        if (empty($items)) {
            return ['success' => false, 'message' => 'Cart is empty'];
        }
        
        $totals = calculateCartTotals($conn, $cartId, $userId, $items);
        $amount = $totals['total_amount'];
        
        // Generate transaction reference
        $transactionId = 'TXN-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $reference = 'REF-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Log payment attempt
        $logStmt = $conn->prepare(
            "INSERT INTO payment_logs 
             (user_id, cart_id, amount, payment_method, transaction_id, reference, status, created_at)
             VALUES (:user_id, :cart_id, :amount, :payment_method, :transaction_id, :reference, 'pending', NOW())"
        );
        $logStmt->execute([
            ':user_id' => $userId,
            ':cart_id' => $cartId,
            ':amount' => $amount,
            ':payment_method' => $paymentMethod,
            ':transaction_id' => $transactionId,
            ':reference' => $reference
        ]);
        
        // In a real app, you would call payment gateway API here
        // For demo, we'll simulate successful payment
        
        // Update payment log to success
        $updateStmt = $conn->prepare(
            "UPDATE payment_logs SET status = 'success', updated_at = NOW() 
             WHERE transaction_id = :transaction_id"
        );
        $updateStmt->execute([':transaction_id' => $transactionId]);
        
        return [
            'success' => true,
            'message' => 'Payment processed successfully',
            'data' => [
                'transaction_id' => $transactionId,
                'reference' => $reference,
                'amount' => $amount,
                'amount_formatted' => 'MK' . number_format($amount, 2),
                'payment_method' => $paymentMethod
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Payment processing error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Payment processing failed: ' . $e->getMessage()
        ];
    }
}

/*********************************
 * CREATE ORDER AFTER PAYMENT
 *********************************/
function createOrderAfterPayment($conn, $userId, $cartId, $totals, $address, $paymentMethod, $transactionId, $reference) {
    // Generate order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Create order
        $stmt = $conn->prepare(
            "INSERT INTO orders 
                (order_number, user_id, merchant_id, merchant_name,
                 subtotal, delivery_fee, discount_amount, total_amount,
                 delivery_address, payment_method, transaction_id, reference,
                 payment_status, status, created_at, updated_at)
             VALUES 
                (:order_number, :user_id, :merchant_id, :merchant_name,
                 :subtotal, :delivery_fee, :discount, :total_amount,
                 :delivery_address, :payment_method, :transaction_id, :reference,
                 'paid', 'confirmed', NOW(), NOW())"
        );
        
        $deliveryAddress = $address ? $address['address_line1'] . ', ' . $address['city'] : 'Address not set';
        $preparationTime = $totals['merchant']['preparation_time'] ?? 20;
        $merchantName = $totals['merchant']['name'] ?? '';
        
        $params = [
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $totals['merchant']['id'],
            ':merchant_name' => $merchantName,
            ':subtotal' => $totals['subtotal'],
            ':delivery_fee' => $totals['delivery_fee'],
            ':discount' => $totals['discount'],
            ':total_amount' => $totals['total_amount'],
            ':delivery_address' => $deliveryAddress,
            ':payment_method' => $paymentMethod,
            ':transaction_id' => $transactionId,
            ':reference' => $reference
        ];
        
        $stmt->execute($params);
        $orderId = $conn->lastInsertId();
        
        // Add order items
        $itemStmt = $conn->prepare(
            "INSERT INTO order_items 
                (order_id, item_name, quantity, price, total,
                 special_instructions, variant_data, add_ons_json, selected_options)
             VALUES 
                (:order_id, :item_name, :quantity, :price, :total,
                 :special_instructions, :variant_data, :add_ons_json, :selected_options)"
        );
        
        foreach ($totals['items'] as $item) {
            $variantData = isset($item['variant_data']) ? json_encode($item['variant_data']) : null;
            $addOnsJson = isset($item['add_ons']) ? json_encode($item['add_ons']) : null;
            $selectedOptions = isset($item['selected_options']) ? json_encode($item['selected_options']) : null;
            
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['total'],
                ':special_instructions' => $item['notes'] ?? '',
                ':variant_data' => $variantData,
                ':add_ons_json' => $addOnsJson,
                ':selected_options' => $selectedOptions
            ]);
        }
        
        // Create tracking
        $trackStmt = $conn->prepare(
            "INSERT INTO order_tracking (order_id, status, created_at)
             VALUES (:order_id, 'Order placed', NOW())"
        );
        $trackStmt->execute([':order_id' => $orderId]);
        
        // Clear cart
        $clearStmt = $conn->prepare("UPDATE cart_items SET is_active = 0 WHERE cart_id = :cart_id");
        $clearStmt->execute([':cart_id' => $cartId]);
        
        $updateCartStmt = $conn->prepare("UPDATE carts SET status = 'completed' WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cartId]);
        
        // Update payment log with order_id
        $updateLogStmt = $conn->prepare(
            "UPDATE payment_logs SET order_id = :order_id WHERE transaction_id = :transaction_id"
        );
        $updateLogStmt->execute([
            ':order_id' => $orderId,
            ':transaction_id' => $transactionId
        ]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Order creation error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
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
        
        // Get wallet balance (if exists)
        $walletStmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = :user_id AND is_active = 1");
        $walletStmt->execute([':user_id' => $userId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
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
            'wallet' => [
                'balance' => $wallet ? floatval($wallet['balance']) : 0,
                'balance_formatted' => $wallet ? 'MK' . number_format($wallet['balance'], 2) : 'MK0.00',
                'exists' => $wallet ? true : false
            ],
            'payment_methods' => [
                [
                    'id' => PAYMENT_METHOD_DROPX_WALLET,
                    'name' => 'DropX Wallet',
                    'type' => 'wallet',
                    'icon' => 'wallet',
                    'description' => 'Pay using your DropXWallet balance'
                ],
                [
                    'id' => PAYMENT_METHOD_AIRTEL_MONEY,
                    'name' => 'Airtel Money',
                    'type' => 'mobile_money',
                    'icon' => 'airtel',
                    'description' => 'Pay via Airtel Money',
                    'provider' => 'Airtel Malawi',
                    'min_amount' => 100,
                    'max_amount' => 1000000,
                    'dropx_number' => DROPX_AIRTEL_MONEY_NUMBER
                ],
                [
                    'id' => PAYMENT_METHOD_TNM_MPAMBA,
                    'name' => 'TNM Mpamba',
                    'type' => 'mobile_money',
                    'icon' => 'tnm',
                    'description' => 'Pay via TNM Mpamba',
                    'provider' => 'TNM',
                    'min_amount' => 100,
                    'max_amount' => 1000000,
                    'dropx_number' => DROPX_TNM_MPAMBA_NUMBER
                ],
                [
                    'id' => PAYMENT_METHOD_BANK_TRANSFER,
                    'name' => 'Bank Transfer',
                    'type' => 'bank',
                    'icon' => 'bank',
                    'description' => 'Pay via bank transfer',
                    'min_amount' => 1000,
                    'max_amount' => 10000000,
                    'bank_details' => [
                        'bank_name' => DROPX_BANK_NAME,
                        'account_name' => DROPX_BANK_ACCOUNT_NAME,
                        'account_number' => DROPX_BANK_ACCOUNT_NUMBER
                    ]
                ]
            ]
        ]);
    }
    
    /*********************************
     * PROCESS PAYMENT (NEW - PAYMENT FIRST)
     *********************************/
    elseif ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $cartId = $input['cart_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? null;
        $paymentDetails = $input['payment_details'] ?? [];
        
        if (!$cartId) {
            ResponseHandler::error('Cart ID required', 400);
        }
        
        if (!$paymentMethod) {
            ResponseHandler::error('Payment method required', 400);
        }
        
        // Verify cart belongs to user
        $cartStmt = $conn->prepare("SELECT id FROM carts WHERE id = :id AND user_id = :user_id AND status = 'active'");
        $cartStmt->execute([':id' => $cartId, ':user_id' => $userId]);
        
        if (!$cartStmt->fetch()) {
            ResponseHandler::error('Cart not found or not active', 404);
        }
        
        // Process payment
        $result = processPayment($conn, $userId, $cartId, $paymentMethod, $paymentDetails);
        
        if ($result['success']) {
            ResponseHandler::success($result['data'], 'Payment processed successfully');
        } else {
            ResponseHandler::error($result['message'], 400);
        }
    }
    
    /*********************************
     * CREATE ORDER AFTER PAYMENT
     *********************************/
    elseif ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $cartId = $input['cart_id'] ?? null;
        $transactionId = $input['transaction_id'] ?? null;
        $reference = $input['reference'] ?? null;
        $paymentMethod = $input['payment_method'] ?? null;
        
        if (!$cartId || !$transactionId || !$reference || !$paymentMethod) {
            ResponseHandler::error('Cart ID, transaction ID, reference and payment method required', 400);
        }
        
        // Verify payment was successful
        $paymentStmt = $conn->prepare(
            "SELECT * FROM payment_logs 
             WHERE transaction_id = :transaction_id AND cart_id = :cart_id AND status = 'success'"
        );
        $paymentStmt->execute([
            ':transaction_id' => $transactionId,
            ':cart_id' => $cartId
        ]);
        
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            ResponseHandler::error('Payment not found or not successful', 400);
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
        $order = createOrderAfterPayment(
            $conn, $userId, $cartId, $totals, $address, 
            $paymentMethod, $transactionId, $reference
        );
        
        if ($order['success']) {
            ResponseHandler::success([
                'order_id' => $order['order_id'],
                'order_number' => $order['order_number'],
                'amount' => $totals['total_amount'],
                'amount_formatted' => $totals['total_amount_formatted'],
                'merchant' => [
                    'id' => $totals['merchant']['id'],
                    'name' => $totals['merchant']['name']
                ]
            ], 'Order created successfully');
        } else {
            ResponseHandler::error('Failed to create order: ' . $order['message'], 500);
        }
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
            $paymentStatus = $input['payment_status'] ?? 'paid';
            
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
                     SET payment_status = 'paid', status = 'confirmed', updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $orderId]);
                
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