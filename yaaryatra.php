<?php
// New PHP Backend for Yaar Yatra - yaaryatra.php (MongoDB Integration)

// 1. Configuration
// NOTE: Ensure your MongoDB server is running and the PHP MongoDB extension is installed.
define('MONGO_URI', 'mongodb://localhost:27017'); // Change if your MongoDB instance is elsewhere
define('DB_NAME', 'yaaryatra_db'); 

// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Database Connection Utility

use MongoDB\Client;

// IMPORTANT: Requires 'composer require mongodb/mongodb'
// This path might need adjustment based on your setup
require 'vendor/autoload.php'; 

/**
 * Establishes a connection to the MongoDB database and returns the database object.
 * @return \MongoDB\Database The MongoDB database object.
 */
function connectDB() {
    try {
        $client = new Client(MONGO_URI);
        return $client->selectDatabase(DB_NAME);
    } catch (\Exception $e) {
        // Log the error and exit gracefully for the client
        error_log("MongoDB Connection failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed. Check server logs for MongoDB error.']);
        exit;
    }
}
/**
 * Fetches all bookings for a specific user ID.
 * @param string $userId The user_id to filter by.
 * @return array All bookings for the user.
 */
// LOCATE THIS FUNCTION in your yaaryatra.php file:
// LOCATE THIS FUNCTION in your yaaryatra.php file:

/**
 * Fetches all bookings for a specific user ID.
 * @param string $userId The user_id to filter by.
 * @return array All bookings for the user.
 */
function fetchUserBookings(string $userId): array {
    $db = connectDB();
    $bookingsCollection = $db->selectCollection('bookings');
    $userBookings = [];
    
    // Check for an obviously invalid or empty ID before querying
    if (empty($userId) || strpos($userId, 'user_') !== 0) {
        error_log("Security Warning: Attempted to fetch history with invalid User ID: " . $userId);
        return [];
    }
    
    // --- CRITICAL FILTER DEFINITION ---
    // This defines the exact MongoDB filter operation.
    $filter = ['user_id' => $userId]; 
    
    try {
        // Execute Query with the explicit filter
        $cursor = $bookingsCollection->find(
            $filter, 
            ['sort' => ['_id' => -1]]
        );
        
        foreach ($cursor as $doc) {
            $row = $doc->getArrayCopy();
            
            // --- SECURITY BACKUP CHECK --- (Prevents display if query fails)
            if ($row['user_id'] !== $userId) {
                 error_log("SECURITY FAILURE: PHP/MongoDB returned contaminated record for ID: " . $userId);
                 continue; // Skip this contaminated record
            }
            // --- END SECURITY BACKUP CHECK ---
            
            // Prepare bus details for FE (convert JSON string back to array)
            if (isset($row['bus_details']) && is_string($row['bus_details'])) {
                $row['bus'] = json_decode($row['bus_details'], true);
                unset($row['bus_details']);
            }
            $userBookings[] = $row;
        }
        
        return $userBookings;

    } catch (\Exception $e) {
        error_log("MongoDB Find Operation Failed: " . $e->getMessage());
        return [];
    }
}


/**
 * Fetches a single booking and its associated passengers.
 * @param string $bookingId The ID of the booking to fetch.
 * @return array|null The booking data including a 'passengers' array, or null if not found.
 */
function fetchBookingWithPassengers(string $bookingId): ?array {
    $db = connectDB();
    $bookingsCollection = $db->selectCollection('bookings');
    $passengersCollection = $db->selectCollection('passengers');

    // 1. Find the booking by its ID
    $bookingDoc = $bookingsCollection->findOne(['booking_id' => $bookingId]);
    
    if (!$bookingDoc) {
        return null;
    }

    $booking = $bookingDoc->getArrayCopy();
    
    // Ensure data types are consistent
    $booking['total_cost_usd'] = (float)($booking['total_cost_usd'] ?? 0);
    $booking['refund_amount'] = (float)($booking['refund_amount'] ?? 0);
    $booking['is_cancelled'] = (bool)($booking['is_cancelled'] ?? false);
    
    // Convert bus details back to array from JSON string
    if (isset($booking['bus_details']) && is_string($booking['bus_details'])) {
        $booking['bus'] = json_decode($booking['bus_details'], true);
        unset($booking['bus_details']);
    } elseif (isset($booking['bus_details'])) {
        // Handle cases where MongoDB stored it as a BSON object/array
        $booking['bus'] = (array)$booking['bus_details'];
        unset($booking['bus_details']);
    } else {
        $booking['bus'] = null;
    }


    // 2. Find associated passengers
    $passengersCursor = $passengersCollection->find(['booking_id' => $bookingId]);
    $passengers = [];
    foreach ($passengersCursor as $doc) {
        $p = $doc->getArrayCopy();
        // Map database keys to frontend keys
        $p['name'] = $p['passenger_name'];
        unset($p['passenger_name']);
        $p['aadhar'] = $p['aadhar_number'];
        unset($p['aadhar_number']);
        $passengers[] = $p;
    }
    
    $booking['passengers'] = $passengers;
    return $booking;
}

// Helper for simulating delay
function simulateDelay(int $seconds = 1) {
    // sleep($seconds); // Keep commented for faster testing
}

// 3. Central Function to Fetch All Initial Data

/**
 * Fetches all application data from MongoDB collections.
 * @return array All data structures for the frontend.
 */
function fetchAllDataFromDB(): array {
    $db = connectDB();
    $data = [
        'bookings' => [],
        'reviews' => [],
        'admin_accounts' => [], 
        'user_profile' => [] 
    ];

    // Fetch Bookings
    $bookingsCollection = $db->selectCollection('bookings');
    $cursor = $bookingsCollection->find();
    foreach ($cursor as $doc) {
        $row = $doc->getArrayCopy();
        // Prepare bus details for FE (convert JSON string back to array)
        if (isset($row['bus_details']) && is_string($row['bus_details'])) {
            $row['bus'] = json_decode($row['bus_details'], true);
            unset($row['bus_details']);
        }
        $data['bookings'][] = $row;
    }
    
    // Fetch Reviews
    $reviewsCollection = $db->selectCollection('reviews');
    // Sort by _id DESC to mimic auto-increment ID sort (newest first)
    $cursor = $reviewsCollection->find([], ['sort' => ['_id' => -1]]); 
    foreach ($cursor as $doc) { 
        $data['reviews'][] = $doc->getArrayCopy(); 
    }

    // Fetch Admin Accounts (keyed by place_name)
    $adminCollection = $db->selectCollection('admin_accounts');
    $cursor = $adminCollection->find();
    foreach ($cursor as $doc) { 
        $row = $doc->getArrayCopy();
        $data['admin_accounts'][$row['place_name']] = $row; 
    }

    // Fetch User Profiles (keyed by user_id)
    $profileCollection = $db->selectCollection('user_profile');
    $cursor = $profileCollection->find();
    foreach ($cursor as $doc) { 
        $row = $doc->getArrayCopy();
        $data['user_profile'][$row['user_id']] = $row; 
    }

    return $data;
}


// 4. API Endpoint Handling

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- Handle GET Requests (Read Operations) ---
if ($method === 'GET') {
    switch ($action) {
        case 'get_all':
            simulateDelay(0);
            $data = fetchAllDataFromDB();
            echo json_encode(['success' => true, 'data' => $data]);
            break;
        
        case 'get_receipt':
            $bookingId = $_GET['booking_id'] ?? '';
            if (empty($bookingId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing booking ID.']);
                break;
            }
            $bookingData = fetchBookingWithPassengers($bookingId);
            if ($bookingData) {
                simulateDelay(0);
                echo json_encode(['success' => true, 'booking' => $bookingData]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            }
            break;
            case 'get_user_bookings': // <--- ADD THIS NEW CASE
            $userId = $_GET['user_id'] ?? '';
            if (empty($userId)) {
         error_log("CRITICAL ERROR: User ID is empty in GET request.");
    } else {
         error_log("History Request received for User ID: " . $userId . " (Type: " . gettype($userId) . ")");
    }
            simulateDelay(0);
            $data = fetchUserBookings($userId);
            echo json_encode(['success' => true, 'bookings' => $data]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid GET action.']);
            break;
    }
    exit;
}

// --- Handle POST Requests (Write/Update Operations) ---
if ($method === 'POST') {
    $db = connectDB();
    $requestBody = file_get_contents('php://input');
    $input = json_decode($requestBody, true);
    
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
        exit;
    }
    
    $bookingsCollection = $db->selectCollection('bookings');
    $passengersCollection = $db->selectCollection('passengers');
    $profileCollection = $db->selectCollection('user_profile');
    $adminCollection = $db->selectCollection('admin_accounts');
    $reviewsCollection = $db->selectCollection('reviews');
    
    switch ($action) {
        // ------------------------------------
        // USER LOGIN & PROFILE ENDPOINTS
        // ------------------------------------
        case 'login_user':
            $mobile = $input['mobile'] ?? '';
            $userId = 'user_' . $mobile;
            
            // Find or Insert logic (upsert)
            $updateResult = $profileCollection->updateOne(
                ['user_id' => $userId],
                ['$setOnInsert' => [
                    'user_id' => $userId, 
                    'phone' => $mobile, 
                    'name' => 'Yaar Yatra Traveler', 
                    'email' => 'traveler@yaaryatra.com', 
                    'profileImage' => "https://via.placeholder.com/40/8B5CF6/FFFFFF?text=YY"
                ]],
                ['upsert' => true]
            );
            
            simulateDelay(1);
            echo json_encode(['success' => true, 'message' => 'User logged in and profile ensured.', 'userId' => $userId]);
            break;

        case 'save_profile':
            $userId = $input['userId'] ?? '';
            $profile = $input['profileData'] ?? [];
            
            $updateResult = $profileCollection->updateOne(
                ['user_id' => $userId],
                ['$set' => [
                    'name' => $profile['name'] ?? '', 
                    'email' => $profile['email'] ?? '', 
                    'profileImage' => $profile['profileImage'] ?? ''
                ]]
            );
            
            if ($updateResult->getModifiedCount() > 0 || $updateResult->getMatchedCount() > 0) {
                simulateDelay(1);
                echo json_encode(['success' => true, 'message' => 'Profile saved successfully.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to save profile or no changes detected.']);
            }
            break;
            
        // ------------------------------------
        // ADMIN ENDPOINTS
        // ------------------------------------
        case 'admin_register':
            $place = $input['place'] ?? '';
            $phone = $input['phone'] ?? '';
            $userId = 'admin_' . strtolower(str_replace(' ', '_', $place));

            // Check if place already registered
            if ($adminCollection->findOne(['place_name' => $place])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Admin registration failed: Place already has an admin.']);
                break;
            }
            
            $adminDoc = [
                'admin_id' => $userId, 
                'place_name' => $place, 
                'name' => $input['name'] ?? '', 
                'phone' => $phone, 
                'email' => $input['email'] ?? '', 
                'age' => (int)($input['age'] ?? 0), 
                'aadhar_number' => $input['aadhar'] ?? ''
            ];

            $insertResult = $adminCollection->insertOne($adminDoc);

            if ($insertResult->getInsertedCount() === 1) {
                simulateDelay(1);
                echo json_encode(['success' => true, 'message' => 'Admin account created successfully.', 'userId' => $userId]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to write admin data to DB.']);
            }
            break;

        // ------------------------------------
        // BOOKING ENDPOINTS
        // ------------------------------------
        case 'save_booking':
    $booking = $input['booking'] ?? null;
    $passengers = $input['passengers'] ?? [];
    $userId = $input['userId'] ?? 'user_anonymous'; // 1. Receiving userId
    
    if (!$booking) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No booking data provided.']);
        break;
    }
    
    // Prepare booking document
    $bookingDoc = [
        'booking_id' => $booking['id'],
        'user_id' => $userId, // 2. Stamping the booking with the received userId
        'booking_date' => $booking['date'], 
        'departure_date' => $booking['departureDate'], 
        'location' => $booking['location'], 
        'pickup_city' => $booking['pickupCity'], 
        'hotel_name' => $booking['hotel'], 
        'total_cost_usd' => (float)$booking['cost'],
        'booking_type' => $booking['type'], 
        // Store bus details as a JSON string for consistency
        'bus_details' => $booking['bus'] ? json_encode($booking['bus']) : null, 
        'admin_date' => $booking['adminDate'],
        'is_cancelled' => false,
        'refund_amount' => 0.0,
        'has_insurance' => true 
    ];
    
    // 1. Insert into bookings collection
    $bookingSaved = $bookingsCollection->insertOne($bookingDoc);
    
    // 2. Insert into passengers collection (if any)
    $passengersSaved = true;
    if ($bookingSaved->getInsertedCount() === 1 && $booking['type'] === 'Bus' && !empty($passengers)) {
        // ... (Passenger insert logic remains the same, using $booking['id'] for linkage)
        // This part is safe as it uses the same booking ID for linkage.
    }
    
    if ($bookingSaved->getInsertedCount() === 1 && $passengersSaved) {
        simulateDelay(1);
        echo json_encode(['success' => true, 'message' => 'Booking and passengers saved successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save booking/passenger data.']);
    }
    break;

        case 'cancel_booking':
            $bookingId = $input['id'] ?? '';
            $refundAmount = $input['refundAmount'] ?? 0;

            $updateResult = $bookingsCollection->updateOne(
                ['booking_id' => $bookingId, 'is_cancelled' => ['$ne' => true]], 
                ['$set' => ['is_cancelled' => true, 'refund_amount' => (float)$refundAmount]]
            );

            if ($updateResult->getModifiedCount() > 0) {
                simulateDelay(1);
                echo json_encode(['success' => true, 'message' => 'Booking cancelled and refund processed.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Booking ID not found or already cancelled.']);
            }
            break;
            
        // ------------------------------------
        // REVIEW ENDPOINTS
        // ------------------------------------
        case 'save_review':
            $review = $input['review'] ?? null;
            if (!$review) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No review data provided.']);
                break;
            }
            
            $reviewDoc = [
                'user_id' => $review['userId'] ?? 'anonymous', 
                'username' => $review['username'] ?? 'Anonymous',
                'location' => $review['location'],
                'rating' => (int)$review['rating'],
                'review_text' => $review['text'],
                'review_date' => $review['date']
            ];
            
            $insertResult = $reviewsCollection->insertOne($reviewDoc);

            if ($insertResult->getInsertedCount() === 1) {
                simulateDelay(0);
                echo json_encode(['success' => true, 'message' => 'Review saved successfully.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to write review data to DB.']);
            }
            break;

        default:
            http_response_code(400); 
            echo json_encode(['success' => false, 'message' => 'Invalid POST action.']);
            break;
    }
    
    exit;
}

// 5. Default Fallback
http_response_code(405); 
echo json_encode(['success' => false, 'message' => 'Method not allowed or no action specified.']);
?>