<?php

// A simple standalone script to test GOV.UK Pay API authentication
echo "Testing GOV.UK Pay API authentication...\n\n";

// Hard-code the API key from the Drupal configuration for this test
// This should be the same key that's stored in your Drupal configuration
$api_key = 'api_test_82t1tpoa198ser9g7ce2f882h3bjt74hm372ciurs403lhqgefdefg7hfb';
echo "Using API key (first/last 5 chars): " . substr($api_key, 0, 5) . "..." . substr($api_key, -5) . "\n\n";

// Make a direct curl request to the GOV.UK Pay API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://publicapi.payments.service.gov.uk/v1/payments");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HEADER, TRUE);
curl_setopt($ch, CURLOPT_POST, TRUE);

// Set up test payment data
$data = [
  "amount" => 1000,
  "reference" => "TEST" . date('YmdHis'),
  "description" => "Test payment",
  "return_url" => "https://example.com/return"
];

// Test 1: With "Bearer" prefix (per GOV.UK Pay documentation)
echo "== Test 1: With 'Bearer' prefix ==\n";
$headers = [
  "Content-Type: application/json",
  "Authorization: Bearer " . $api_key
];

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

// Execute the request
$response = curl_exec($ch);
$info = curl_getinfo($ch);

// Output detailed information about the request and response
echo "HTTP Status Code: " . $info['http_code'] . "\n";
$header_size = $info['header_size'];
$header = substr($response, 0, $header_size);
$body = substr($response, $header_size);
echo "Response Body: " . $body . "\n\n";

// Test 2: Without the "Bearer" prefix
echo "== Test 2: Without 'Bearer' prefix ==\n";
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Content-Type: application/json",
  "Authorization: " . $api_key
]);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
echo "HTTP Status Code: " . $info['http_code'] . "\n";
$header_size = $info['header_size'];
$body = substr($response, $header_size);
echo "Response Body: " . $body . "\n\n";

// Test 3: With only an "api_key" query parameter
echo "== Test 3: With query parameter ==\n";
curl_setopt($ch, CURLOPT_URL, "https://publicapi.payments.service.gov.uk/v1/payments?api_key=" . $api_key);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Content-Type: application/json"
]);
$response = curl_exec($ch);
$info = curl_getinfo($ch);
echo "HTTP Status Code: " . $info['http_code'] . "\n";
$header_size = $info['header_size'];
$body = substr($response, $header_size);
echo "Response Body: " . $body . "\n\n";

curl_close($ch);
