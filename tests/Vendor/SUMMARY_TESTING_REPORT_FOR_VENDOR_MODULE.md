# Summary Testing Report for Vendor Module

## Report Metadata
- Document: SUMMARY_TESTING_REPORT_FOR_VENDOR_MODULE.md
- Location: tests/Vendor/SUMMARY_TESTING_REPORT_FOR_VENDOR_MODULE.md

## Test Suite Structure (Vendor)

```text
tests/Vendor/
`-- Feature/
    |-- Authentication/
    |   |-- VendorConfTest.php
    |   |-- VendorLogTest.php
    |   `-- VendorRegTest.php
    |-- Dash_Profile/
    |   |-- AdminProfTest.php
    |   |-- VendorDasTest.php
    |   `-- VendorProfTest.php
    `-- Orders/
        |-- VendorOrdDetTest.php
        |-- VendorOrdIteStaTest.php
        `-- VendorOrdTest.php
```

## Test Execution Commands

```bash
./vendor/bin/phpunit tests/Vendor
```

Run by feature group:

```bash
./vendor/bin/phpunit tests/Vendor/Feature/Authentication
./vendor/bin/phpunit tests/Vendor/Feature/Dash_Profile
./vendor/bin/phpunit tests/Vendor/Feature/Orders
```

## Execution Snapshot (Provided Run Log)

### Summary Metrics
- Runtime: PHP 8.3.30, PHPUnit 10.5.58
- Duration: 00:19.452
- Memory: 60.00 MB
- Total tests: 90
- Total assertions: 425
- Failures: 12
- Passed: 78
- Status: FAILED

### Failed Tests with Raw Logs and Failing Test Snippets

---

### Failure 1
**Test**: `Tests\Vendor\Feature\Authentication\VendorLogTest::test_vendor_blocked_unconfirmed`

**Failure Log**
```text
1) Tests\Vendor\Feature\Authentication\VendorLogTest::test_vendor_blocked_unconfirmed
The user is authenticated
Failed asserting that true is false.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication.php:62
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorLogTest.php:212
```

**Failing Test Code**
```php
public function test_vendor_blocked_unconfirmed(): void
{
    $account = $this->createVendorLoginAccount([
        'email' => 'vendor.unconfirmed@example.com',
        'password' => 'secret123',
        'confirm' => 'No',
        'status' => 1,
    ]);

    $response = $this->from('/admin/login')->post('/admin/login', [
        'email' => $account['admin']->email,
        'password' => 'secret123',
    ]);

    $response->assertRedirect('/admin/login');
    $response->assertSessionHas('error_message', 'Please confirm your email to activate your Vendor Account');
    $this->assertGuest('admin');

    $this->assertDatabaseHas('admins', [
        'email' => 'vendor.unconfirmed@example.com',
        'type' => 'vendor',
        'confirm' => 'No',
    ]);
    $this->assertDatabaseHas('vendors', [
        'email' => 'vendor.unconfirmed@example.com',
        'confirm' => 'No',
    ]);
}
```

---

### Failure 2
**Test**: `Tests\Vendor\Feature\Authentication\VendorLogTest::test_security_blocks_inactive_vendor`

**Failure Log**
```text
2) Tests\Vendor\Feature\Authentication\VendorLogTest::test_security_blocks_inactive_vendor
Failed asserting that two strings are equal.
--- Expected
+++ Actual
@@ @@
-'http://localhost/admin/login'
+'http://localhost/admin/dashboard'

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:312
D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:179
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorLogTest.php:282
```

**Failing Test Code**
```php
public function test_security_blocks_inactive_vendor(): void
{
    $account = $this->createVendorLoginAccount([
        'email' => 'vendor.inactive@example.com',
        'password' => 'secret123',
        'confirm' => 'Yes',
        'status' => 0,
    ]);

    $response = $this->from('/admin/login')->post('/admin/login', [
        'email' => $account['admin']->email,
        'password' => 'secret123',
    ]);

    $response->assertRedirect('/admin/login');
    $response->assertSessionHas('error_message', 'Your vendor account is not active');
    $this->assertGuest('admin');

    $this->assertDatabaseHas('admins', [
        'email' => 'vendor.inactive@example.com',
        'type' => 'vendor',
        'confirm' => 'Yes',
        'status' => 0,
    ]);
    $this->assertDatabaseHas('vendors', [
        'email' => 'vendor.inactive@example.com',
        'confirm' => 'Yes',
        'status' => 0,
    ]);
}
```

---

### Failure 3
**Test**: `Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_invalid_email`

**Failure Log**
```text
3) Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_invalid_email
Session is missing expected key [errors].
Failed asserting that false is true.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1263
D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1340
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorRegTest.php:154
```

**Failing Test Code**
```php
public function test_register_fails_invalid_email(): void
{
    $payload = $this->validPayload([
        'email' => 'user@invalid',
        'mobile' => '0912345679',
    ]);

    $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

    $response->assertRedirect('/vendor/login-register');
    $response->assertSessionHasErrors(['email']);

    $this->assertDatabaseMissing('vendors', ['mobile' => '0912345679']);
    $this->assertDatabaseMissing('admins', ['mobile' => '0912345679']);
}
```

---

### Failure 4
**Test**: `Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_mobile_too_short`

**Failure Log**
```text
4) Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_mobile_too_short
Session is missing expected key [errors].
Failed asserting that false is true.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1263
D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1340
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorRegTest.php:231
```

**Failing Test Code**
```php
public function test_register_fails_mobile_too_short(): void
{
    $payload = $this->validPayload([
        'email' => 'tc08@example.com',
        'mobile' => '09123',
    ]);

    $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

    $response->assertRedirect('/vendor/login-register');
    $response->assertSessionHasErrors(['mobile']);

    $this->assertDatabaseMissing('vendors', ['email' => 'tc08@example.com']);
    $this->assertDatabaseMissing('admins', ['email' => 'tc08@example.com']);
}
```

---

### Failure 5
**Test**: `Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_password_missing_key`

**Failure Log**
```text
5) Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_password_missing_key
Failed asserting that a row in the table [vendors] does not match the attributes {
    "email": "tc12@example.com"
}.

Found similar results: [
    {
        "email": "tc12@example.com"
    }
].

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Foundation\Testing\Concerns\InteractsWithDatabase.php:50
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorRegTest.php:313
```

**Failing Test Code**
```php
public function test_register_password_missing_key(): void
{
    Mail::fake();

    $payload = $this->validPayload([
        'email' => 'tc12@example.com',
        'mobile' => '0955555555',
    ]);
    unset($payload['password']);

    $response = $this->post('/vendor/register', $payload);

    $response->assertStatus(500);

    $this->assertDatabaseMissing('vendors', ['email' => 'tc12@example.com']);
    $this->assertDatabaseMissing('admins', ['email' => 'tc12@example.com']);
}
```

---

### Failure 6
**Test**: `Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_password_empty_string`

**Failure Log**
```text
6) Tests\Vendor\Feature\Authentication\VendorRegTest::test_register_fails_password_empty_string
Session is missing expected key [errors].
Failed asserting that false is true.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1263
D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1340
D:\laragon\www\last-project\tests\Vendor\Feature\Authentication\VendorRegTest.php:329
```

**Failing Test Code**
```php
public function test_register_fails_password_empty_string(): void
{
    $payload = $this->validPayload([
        'email' => 'tc13@example.com',
        'mobile' => '0966666666',
        'password' => '',
    ]);

    $response = $this->from('/vendor/login-register')->post('/vendor/register', $payload);

    $response->assertRedirect('/vendor/login-register');
    $response->assertSessionHasErrors(['password']);

    $this->assertDatabaseMissing('vendors', ['email' => 'tc13@example.com']);
    $this->assertDatabaseMissing('admins', ['email' => 'tc13@example.com']);
}
```

---

### Failure 7
**Test**: `Tests\Vendor\Feature\Dash_Profile\AdminProfTest::test_admin_details_invalid_file`

**Failure Log**
```text
7) Tests\Vendor\Feature\Dash_Profile\AdminProfTest::test_admin_details_invalid_file
Expected response status code [201, 301, 302, 303, 307, 308] but received 500.
Failed asserting that false is true.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:173
D:\laragon\www\last-project\tests\Vendor\Feature\Dash_Profile\AdminProfTest.php:275
```

**Failing Test Code**
```php
public function test_admin_details_invalid_file(): void
{
    $this->actingAsAdmin(['image' => null]);

    $tmpPath = tempnam(sys_get_temp_dir(), 'bad_upload_');
    file_put_contents($tmpPath, 'not-an-image');

    $invalidUpload = new UploadedFile(
        $tmpPath,
        'broken.png',
        'image/png',
        UPLOAD_ERR_CANT_WRITE,
        true
    );

    $response = $this->post('/admin/update-admin-details', [
        'admin_name' => 'Admin Invalid File',
        'admin_mobile' => '0944555666',
        'admin_image' => $invalidUpload,
    ]);

    $response->assertRedirect('/admin/update-admin-details');
    $response->assertSessionHasErrors(['admin_image']);
}
```

---

### Failure 8
**Test**: `Tests\Vendor\Feature\Dash_Profile\VendorDasTest::test_dashboard_revenue_by_day_logic_defect`

**Failure Log**
```text
8) Tests\Vendor\Feature\Dash_Profile\VendorDasTest::test_dashboard_revenue_by_day_logic_defect
Failed asserting that false is true.

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php:1062
D:\laragon\www\last-project\tests\Vendor\Feature\Dash_Profile\VendorDasTest.php:649
```

**Failing Test Code**
```php
public function test_dashboard_revenue_by_day_logic_defect(): void
{
    for ($dayOffset = 0; $dayOffset < 10; $dayOffset++) {
        $day = Carbon::today()->subDays($dayOffset);
        $this->seedOrder([
            'order_status' => 'Shipped',
            'grand_total' => 100,
            'shipping_charges' => 10,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
    }

    $this->actingAs($this->createVendorTypeAdminAccount(), 'admin');

    $response = $this->get('/admin/dashboard');

    $response->assertStatus(200);
    $response->assertViewHas('revenueByDay', function ($revenueByDay): bool {
        $latestDay = Carbon::today()->toDateString();
        return $revenueByDay->first()->date === $latestDay;
    });
}
```

---

### Failure 9
**Test**: `Tests\Vendor\Feature\Dash_Profile\VendorProfTest::test_vendor_invalid_slug`

**Failure Log**
```text
9) Tests\Vendor\Feature\Dash_Profile\VendorProfTest::test_vendor_invalid_slug
Failed asserting that false is true.

D:\laragon\www\last-project\tests\Vendor\Feature\Dash_Profile\VendorProfTest.php:615
```

**Failing Test Code**
```php
public function test_vendor_invalid_slug(): void
{
    $this->seedCountries();
    $this->actingAsVendorAdmin();

    $response = $this->get('/admin/update-vendor-details/not-a-real-slug');

    $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
}
```

---

### Failure 10
**Test**: `Tests\Vendor\Feature\Orders\VendorOrdDetTest::test_order_details_invalid_order_id_graceful`

**Failure Log**
```text
10) Tests\Vendor\Feature\Orders\VendorOrdDetTest::test_order_details_invalid_order_id_graceful
Failed asserting that false is true.

D:\laragon\www\last-project\tests\Vendor\Feature\Orders\VendorOrdDetTest.php:449
```

**Failing Test Code**
```php
public function test_order_details_invalid_order_id_graceful(): void
{
    $admin = $this->createAdminAccount([
        'vendor_id' => 1001,
        'status' => 1,
        'email' => 'vendor-invalid-order@example.com',
    ]);
    $this->actingAs($admin, 'admin');

    $response = $this->get('/admin/orders/999999');

    $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
}
```

---

### Failure 11
**Test**: `Tests\Vendor\Feature\Orders\VendorOrdIteStaTest::test_update_item_status_invalid_item_id_graceful`

**Failure Log**
```text
11) Tests\Vendor\Feature\Orders\VendorOrdIteStaTest::test_update_item_status_invalid_item_id_graceful
Failed asserting that false is true.

D:\laragon\www\last-project\tests\Vendor\Feature\Orders\VendorOrdIteStaTest.php:409
```

**Failing Test Code**
```php
public function test_update_item_status_invalid_item_id_graceful(): void
{
    $admin = $this->createVendorAdmin(1001, 'vendor-invalid-item@example.com');
    $this->actingAs($admin, 'admin');

    $response = $this->post('/admin/update-order-item-status', [
        'order_item_id' => 999999,
        'order_item_status' => 'Shipped',
    ]);

    $this->assertTrue(in_array($response->getStatusCode(), [302, 404], true));
}
```

---

### Failure 12
**Test**: `Tests\Vendor\Feature\Orders\VendorOrdIteStaTest::test_update_item_status_vendor_cannot_update_other_vendor_item`

**Failure Log**
```text
12) Tests\Vendor\Feature\Orders\VendorOrdIteStaTest::test_update_item_status_vendor_cannot_update_other_vendor_item
Failed asserting that a row in the table [orders_products] matches the attributes {
    "id": 1,
    "item_status": "Pending"
}.

Found similar results: [
    {
        "id": 1,
        "item_status": "Canceled"
    }
].

D:\laragon\www\last-project\vendor\laravel\framework\src\Illuminate\Foundation\Testing\Concerns\InteractsWithDatabase.php:29
D:\laragon\www\last-project\tests\Vendor\Feature\Orders\VendorOrdIteStaTest.php:433
```

**Failing Test Code**
```php
public function test_update_item_status_vendor_cannot_update_other_vendor_item(): void
{
    $userId = $this->createUser('buyer-idor@example.com');
    $orderId = $this->createOrder($userId);
    $itemIdVendorB = $this->createOrderItem($orderId, 2002, ['item_status' => 'Pending']);

    $adminVendorA = $this->createVendorAdmin(1001, 'vendor-a-idor@example.com');
    $this->actingAs($adminVendorA, 'admin');

    $response = $this->post('/admin/update-order-item-status', [
        'order_item_id' => $itemIdVendorB,
        'order_item_status' => 'Canceled',
    ]);

    $this->assertDatabaseHas('orders_products', [
        'id' => $itemIdVendorB,
        'item_status' => 'Pending',
    ]);
}
```

---



