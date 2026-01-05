# Customer Mobile API Documentation

## Base URL
```
http://your-domain.com/api/customer
```

## Authentication
All API endpoints use JSON format for requests and responses.

---

## 1. Login

**Endpoint:** `POST /api/customer/login`

**Description:** Authenticate customer and retrieve their profile, loans, and group members.

**Request Body:**
```json
{
  "username": "0712345678",  // Phone number
  "password": "1234567890"   // Default password
}
```

**Success Response (200):**
```json
{
  "message": "Login successful",
  "status": 200,
  "user_id": 1,
  "name": "John Doe",
  "phone": "255712345678",
  "branch": "Main Branch",
  "group_id": 2,
  "group_name": "Tumaini Group",
  "email": "",
  "memberno": "100001",
  "gender": "M",
  "role": "customer",
  "loans": [
    {
      "loanid": 5,
      "loan_no": "LN001",
      "amount": 1000000,
      "interest": 10,
      "interest_amount": 100000,
      "total_amount": 1100000,
      "period": 12,
      "disbursed_on": "2025-01-01",
      "last_repayment_date": "2025-12-31",
      "status": "disbursed",
      "product_name": "Business Loan",
      "loan_officer": "Jane Smith",
      "repayments": [
        {
          "id": 10,
          "amount": 100000,
          "date": "2025-02-01",
          "receipt_no": "RCP001"
        }
      ],
      "total_repaid": 100000,
      "total_due": 1000000
    }
  ],
  "members": [
    {
      "id": 2,
      "name": "Mary Johnson",
      "phone1": "255723456789",
      "phone2": null,
      "sex": "F",
      "picture": "http://your-domain.com/storage/photos/photo.jpg",
      "loans": []
    }
  ]
}
```

**Error Response (401):**
```json
{
  "message": "User Does Not Exist",
  "status": 401
}
```

or

```json
{
  "message": "Invalid credentials",
  "status": 401
}
```

---

## 2. Get Customer Profile

**Endpoint:** `POST /api/customer/profile`

**Description:** Retrieve detailed customer profile information.

**Request Body:**
```json
{
  "customer_id": 1
}
```

**Success Response (200):**
```json
{
  "status": 200,
  "customer": {
    "id": 1,
    "customerNo": "100001",
    "name": "John Doe",
    "description": "Regular customer",
    "phone1": "255712345678",
    "phone2": "255787654321",
    "work": "Teacher",
    "workAddress": "Dar es Salaam",
    "idType": "NIDA",
    "idNumber": "1234567890",
    "dob": "1990-01-15",
    "sex": "M",
    "category": "Borrower",
    "dateRegistered": "2025-01-01",
    "photo": "http://your-domain.com/storage/photos/photo.jpg",
    "branch": "Main Branch",
    "region": "Dar es Salaam",
    "district": "Kinondoni",
    "group_id": 2,
    "group_name": "Tumaini Group"
  }
}
```

**Error Response (400):**
```json
{
  "message": "Customer ID is required",
  "status": 400
}
```

**Error Response (404):**
```json
{
  "message": "Customer not found",
  "status": 404
}
```

---

## 3. Get Customer Loans

**Endpoint:** `POST /api/customer/loans`

**Description:** Retrieve all loans for a specific customer with repayment details.

**Request Body:**
```json
{
  "customer_id": 1
}
```

**Success Response (200):**
```json
{
  "status": 200,
  "loans": [
    {
      "loanid": 5,
      "loan_no": "LN001",
      "amount": 1000000,
      "interest": 10,
      "interest_amount": 100000,
      "total_amount": 1100000,
      "period": 12,
      "disbursed_on": "2025-01-01",
      "last_repayment_date": "2025-12-31",
      "status": "disbursed",
      "product_name": "Business Loan",
      "loan_officer": "Jane Smith",
      "repayments": [
        {
          "id": 10,
          "amount": 100000,
          "date": "2025-02-01",
          "receipt_no": "RCP001"
        }
      ],
      "total_repaid": 100000,
      "total_due": 1000000
    }
  ]
}
```

---

## 4. Get Group Members

**Endpoint:** `POST /api/customer/group-members`

**Description:** Retrieve all members of the customer's group with their loans.

**Request Body:**
```json
{
  "customer_id": 1
}
```

**Success Response (200):**
```json
{
  "status": 200,
  "members": [
    {
      "id": 2,
      "name": "Mary Johnson",
      "phone1": "255723456789",
      "phone2": null,
      "sex": "F",
      "picture": "http://your-domain.com/storage/photos/photo.jpg",
      "customerNo": "100002",
      "loans": [
        {
          "loanid": 6,
          "loan_no": "LN002",
          "amount": 500000,
          "interest": 10,
          "interest_amount": 50000,
          "total_amount": 550000,
          "period": 6,
          "disbursed_on": "2025-02-01",
          "last_repayment_date": "2025-07-31",
          "status": "disbursed",
          "product_name": "Agriculture Loan",
          "loan_officer": "Jane Smith",
          "repayments": [],
          "total_repaid": 0,
          "total_due": 550000
        }
      ]
    }
  ]
}
```

---

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "username": ["The username field is required."],
    "password": ["The password field is required."]
  }
}
```

### Server Error (500)
```json
{
  "message": "Server error",
  "status": 500,
  "error": "Error details here"
}
```

---

## Phone Number Format

The API accepts phone numbers in multiple formats:
- With country code: `255712345678`
- Without country code: `0712345678`
- With spaces/dashes: `0712-345-678`

The API automatically normalizes phone numbers by:
1. Removing non-numeric characters
2. Removing leading zeros
3. Prepending country code (255)

---

## Testing with cURL

### Login
```bash
curl -X POST http://your-domain.com/api/customer/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "0712345678",
    "password": "1234567890"
  }'
```

### Get Profile
```bash
curl -X POST http://your-domain.com/api/customer/profile \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1
  }'
```

### Get Loans
```bash
curl -X POST http://your-domain.com/api/customer/loans \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1
  }'
```

### Get Group Members
```bash
curl -X POST http://your-domain.com/api/customer/group-members \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1
  }'
```

---

## Flutter Integration Example

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class ApiService {
  static const String baseUrl = 'http://your-domain.com/api/customer';

  // Login
  static Future<Map<String, dynamic>> login(String username, String password) async {
    final response = await http.post(
      Uri.parse('$baseUrl/login'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'username': username,
        'password': password,
      }),
    );

    return jsonDecode(response.body);
  }

  // Get Profile
  static Future<Map<String, dynamic>> getProfile(int customerId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/profile'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'customer_id': customerId,
      }),
    );

    return jsonDecode(response.body);
  }

  // Get Loans
  static Future<Map<String, dynamic>> getLoans(int customerId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/loans'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'customer_id': customerId,
      }),
    );

    return jsonDecode(response.body);
  }

  // Get Group Members
  static Future<Map<String, dynamic>> getGroupMembers(int customerId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/group-members'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'customer_id': customerId,
      }),
    );

    return jsonDecode(response.body);
  }
}
```

---

## Notes

1. **Default Password**: All new customers get a default password of `1234567890`
2. **Phone Number**: Use phone number as username for login
3. **CORS**: If your Flutter app runs on a different domain, ensure CORS is properly configured
4. **HTTPS**: Use HTTPS in production for secure communication
5. **Rate Limiting**: Consider implementing rate limiting for production use
