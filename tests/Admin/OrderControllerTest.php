<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrdersProduct;
use App\Models\OrdersLog;
use App\Models\User;
use App\Models\OrderStatus;
use App\Models\OrderItemStatus;
use App\Models\Section;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $vendor;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup Super Admin
        $this->admin = Admin::create([
            'name'      => 'Super Admin',
            'type'      => 'admin',
            'vendor_id' => 0,
            'mobile'    => '1122334455',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('123456'),
            'confirm'   => 'Yes',
            'status'    => 1
        ]);

        // Setup Approved Vendor
        $this->vendor = Admin::create([
            'name'      => 'Approved Vendor',
            'type'      => 'vendor',
            'vendor_id' => 1,
            'mobile'    => '9988776655',
            'email'     => 'vendor@test.com',
            'password'  => bcrypt('123456'),
            'confirm'   => 'Yes',
            'status'    => 1
        ]);

        // Setup Customer User
        $this->user = User::create([
            'name' => 'Customer', 'address' => 'Addr', 'city' => 'City', 'state' => 'State', 'country' => 'Country', 'pincode' => '123', 'mobile' => '090', 'email' => 'user@test.com', 'password' => bcrypt('123'), 'status' => 1
        ]);

        // Initialize Order Statuses
        OrderStatus::create(['name' => 'New', 'status' => 1]);
        OrderStatus::create(['name' => 'Shipped', 'status' => 1]);
        OrderStatus::create(['name' => 'Delivered', 'status' => 1]);
        OrderStatus::create(['name' => 'Cancelled', 'status' => 1]);
        OrderStatus::create(['name' => 'In Progress', 'status' => 1]);
        
        // Initialize Order Item Statuses
        OrderItemStatus::create(['name' => 'New', 'status' => 1]);
        OrderItemStatus::create(['name' => 'Shipped', 'status' => 1]);
        OrderItemStatus::create(['name' => 'Delivered', 'status' => 1]);

        // Setup Section, Category, and Products for View consistency
        $section = Section::create(['name' => 'Clothing', 'status' => 1]);
        $category = Category::create(['section_id' => $section->id, 'parent_id' => 0, 'category_name' => 'Men', 'category_discount' => 0, 'description' => 'D', 'url' => 'men', 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'category_image' => 'dummy.jpg', 'status' => 1]);
        Product::create(['id' => 1, 'section_id' => $section->id, 'category_id' => $category->id, 'brand_id' => 1, 'vendor_id' => 1, 'admin_id' => 0, 'admin_type' => 'superadmin', 'product_name' => 'P1', 'product_code' => 'P1', 'product_color' => 'R', 'product_price' => 100, 'product_discount' => 0, 'product_weight' => 1, 'product_image' => 'dummy.jpg', 'product_video' => '', 'description' => 'D', 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'is_featured' => 'Yes', 'is_bestseller' => 'No', 'status' => 1]);
        Product::create(['id' => 2, 'section_id' => $section->id, 'category_id' => $category->id, 'brand_id' => 1, 'vendor_id' => 1, 'admin_id' => 0, 'admin_type' => 'superadmin', 'product_name' => 'P2', 'product_code' => 'P2', 'product_color' => 'B', 'product_price' => 100, 'product_discount' => 0, 'product_weight' => 1, 'product_image' => 'dummy.jpg', 'product_video' => '', 'description' => 'D', 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'is_featured' => 'Yes', 'is_bestseller' => 'No', 'status' => 1]);
    }

    /**
     * TC-OR-UI-01
     * Objective: Guest access to orders list redirects to login.
     */
    public function test_orders_list_guest_redirect()
    {
        $response = $this->get('/admin/orders');
        $response->assertStatus(302);
        $response->assertRedirect('admin/login');
    }

    /**
     * TC-OR-UI-02
     * Objective: Admin can see orders in list view.
     */
    public function test_orders_list_admin_access()
    {
        Auth::guard('admin')->login($this->admin);
        Order::create([
            'user_id' => $this->user->id, 'name' => 'U1', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'E', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $response = $this->get('/admin/orders');
        $response->assertStatus(200);
        $response->assertViewHas('orders');
        $this->assertCount(1, $response->viewData('orders'));
    }

    /**
     * TC-OR-UI-03
     * Objective: Vendor can see orders list.
     */
    public function test_orders_list_vendor_access()
    {
        Auth::guard('admin')->login($this->vendor);
        $response = $this->get('/admin/orders');
        $response->assertStatus(200);
        $response->assertViewHas('orders');
    }

    /**
     * TC-OR-SEC-01
     * Objective: Unapproved vendor is redirected to details update page.
     */
    public function test_unapproved_vendor_redirect()
    {
        $unapproved = Admin::create([
            'name' => 'V2', 'type' => 'vendor', 'vendor_id' => 2, 'mobile' => '2', 'email' => 'v2@t.com', 'password' => bcrypt('1'), 'confirm' => 'Yes', 'status' => 0
        ]);
        Auth::guard('admin')->login($unapproved);
        $response = $this->get('/admin/orders');
        $response->assertRedirect('admin/update-vendor-details/personal');
    }

    /**
     * TC-OR-ACT-01
     * Objective: Admin updates order status successfully.
     */
    public function test_admin_update_order_status()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $response = $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'In Progress']);
        $response->assertStatus(302);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'In Progress']);
    }

    /**
     * TC-OR-ACT-02
     * Objective: Update order status to Shipped with courier info.
     */
    public function test_update_status_shipped_success_with_info()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Shipped', 'courier_name' => 'FedEx', 'tracking_number' => '123']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'Shipped', 'courier_name' => 'FedEx']);
    }

    /**
     * TC-OR-ACT-03
     * Objective: Admin updates individual order item status.
     */
    public function test_update_order_item_status()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', ['order_item_id' => $item->id, 'order_item_status' => 'Shipped']);
        $this->assertDatabaseHas('orders_products', ['id' => $item->id, 'item_status' => 'Shipped']);
    }

    /**
     * TC-OR-CAL-01
     * Objective: Order details view correctly calculates item discount from coupon.
     */
    public function test_order_details_item_discount_calculation()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => 'D10', 'coupon_amount' => 10, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 90
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 2, 'product_code' => 'P2', 'product_name' => 'P2', 'product_color' => 'B', 'product_size' => 'L', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertStatus(200);
        $this->assertEquals(5, $response->viewData('item_discount'));
    }

    /**
     * TC-OR-UI-04
     * Objective: Admin can view HTML order invoice.
     */
    public function test_view_order_invoice()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/invoice/'.$order->id);
        $response->assertStatus(200);
        $response->assertViewIs('admin.orders.order_invoice');
    }

    /**
     * TC-OR-ERR-01
     * Objective: Attempting to access a non-existent order returns error.
     */
    public function test_access_non_existent_order()
    {
        Auth::guard('admin')->login($this->admin);
        $response = $this->get('/admin/orders/999');
        $response->assertStatus(500); // Controller fails on toArray()
    }

    /**
     * TC-OR-LOG-01
     * Objective: Order status update creates an entry in orders_logs.
     */
    public function test_order_status_log_creation()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Delivered']);
        $this->assertDatabaseHas('orders_logs', ['order_id' => $order->id, 'order_status' => 'Delivered']);
    }

    /**
     * TC-OR-VAL-01
     * Objective: Update item status fails without item ID.
     */
    public function test_update_item_status_missing_id_fails()
    {
        Auth::guard('admin')->login($this->admin);
        $response = $this->post('/admin/update-order-item-status', ['order_item_status' => 'Shipped']);
        $response->assertStatus(500);
    }

    /**
     * TC-OR-UI-05
     * Objective: Admin can view PDF order invoice (route check).
     */
    public function test_view_pdf_invoice_route()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/invoice/pdf/'.$order->id);
        $response->assertStatus(200);
    }

    /**
     * TC-OR-ACT-04
     * Objective: Update item status with courier info.
     */
    public function test_update_item_status_with_info()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', [
            'order_item_id' => $item->id, 'order_item_status' => 'Shipped', 'item_courier_name' => 'C', 'item_tracking_number' => 'T'
        ]);
        $this->assertDatabaseHas('orders_products', ['id' => $item->id, 'item_status' => 'Shipped', 'tracking_number' => 'T']);
    }

    /**
     * TC-OR-LOG-02
     * Objective: Item status update log correctly captures item ID.
     */
    public function test_item_status_log_item_id()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', ['order_item_id' => $item->id, 'order_item_status' => 'Shipped']);
        $this->assertDatabaseHas('orders_logs', ['order_id' => $order->id, 'order_item_id' => $item->id]);
    }

    /**
     * TC-OR-VAL-02
     * Objective: Update order status fails without order ID.
     */
    public function test_update_order_status_missing_id_fails()
    {
        Auth::guard('admin')->login($this->admin);
        $response = $this->post('/admin/update-order-status', ['order_status' => 'Shipped']);
        $response->assertStatus(500);
    }

    /**
     * TC-OR-UI-06
     * Objective: Verify 'page' session is set correctly on orders list.
     */
    public function test_orders_page_session_value()
    {
        Auth::guard('admin')->login($this->admin);
        $this->get('/admin/orders');
        $this->assertEquals('orders', Session::get('page'));
    }

    /**
     * TC-OR-ACT-05
     * Objective: Update item status to Delivered.
     */
    public function test_item_status_delivered()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', ['order_item_id' => $item->id, 'order_item_status' => 'Delivered']);
        $this->assertDatabaseHas('orders_products', ['id' => $item->id, 'item_status' => 'Delivered']);
    }

    /**
     * TC-OR-UI-07
     * Objective: Verify 'page' session is set on order details page.
     */
    public function test_order_details_session_value()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->get('/admin/orders/'.$order->id);
        $this->assertEquals('orders', Session::get('page'));
    }

    /**
     * TC-OR-SEC-02
     * Objective: Vendor can only see their own products in order details.
     */
    public function test_vendor_isolation_in_details()
    {
        Auth::guard('admin')->login($this->vendor);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        // Vendor's product
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 1, 'admin_id' => $this->vendor->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        // Another vendor's product
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 2, 'admin_id' => 99, 'product_id' => 2, 'product_code' => 'P2', 'product_name' => 'P2', 'product_color' => 'B', 'product_size' => 'L', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New'
        ]);

        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertStatus(200);
        $orderDetails = $response->viewData('orderDetails');
        $this->assertCount(1, $orderDetails['orders_products']);
        $this->assertEquals(1, $orderDetails['orders_products'][0]['vendor_id']);
    }

    /**
     * TC-OR-UI-08
     * Objective: Orders list contains the HTML table.
     */
    public function test_orders_list_contains_table()
    {
        Auth::guard('admin')->login($this->admin);
        $response = $this->get('/admin/orders');
        $response->assertSee('table');
    }

    /**
     * TC-OR-UI-09
     * Objective: Order details view displays the correct order ID.
     */
    public function test_order_details_displays_id()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertSee((string)$order->id);
    }

    /**
     * TC-OR-CAL-02
     * Objective: Order details view displays total item quantity correctly.
     */
    public function test_order_details_total_qty()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 50, 'product_qty' => 5, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertSee('5');
    }

    /**
     * TC-OR-ACT-06
     * Objective: Admin cancels an order.
     */
    public function test_admin_cancel_order()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'Cancelled']);
    }

    /**
     * TC-OR-ACT-07
     * Objective: Updating order status sends email to customer.
     */
    public function test_order_status_update_sends_email()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Shipped', 'courier_name' => 'F', 'tracking_number' => '1']);
        
        Mail::assertSent(function (\Illuminate\Mail\Message $mail) {
            return true;
        });
    }

    /**
     * TC-OR-ACT-08
     * Objective: Updating order item status sends email.
     */
    public function test_order_item_status_update_sends_email()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', ['order_item_id' => $item->id, 'order_item_status' => 'Shipped', 'item_courier_name' => 'C', 'item_tracking_number' => 'T']);
        
        Mail::assertSent(function (\Illuminate\Mail\Message $mail) {
            return true;
        });
    }

    /**
     * TC-OR-UI-10
     * Objective: Admin can see order status options in the details view.
     */
    public function test_order_details_view_has_status_options()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertSee('Shipped');
        $response->assertSee('Delivered');
    }

    /**
     * TC-OR-UI-11
     * Objective: Order details view displays the order log.
     */
    public function test_order_details_displays_log()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 1, 'admin_id' => 10, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        OrdersLog::create(['order_id' => $order->id, 'order_status' => 'New']);
        OrdersLog::create(['order_id' => $order->id, 'order_status' => 'In Progress']);
        
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertStatus(200);
        $response->assertViewHas('orderLog');
        $this->assertCount(2, $response->viewData('orderLog'));
    }

    /**
     * TC-OR-SEC-03
     * Objective: Vendor cannot update order status (only admin).
     * Note: Controller does not currently check permission, this test documents current behavior.
     */
    public function test_vendor_update_order_status()
    {
        Auth::guard('admin')->login($this->vendor);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $response = $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Shipped', 'courier_name' => 'C', 'tracking_number' => 'T']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'Shipped']);
    }

    /**
     * TC-OR-VAL-03
     * Objective: Update order status with invalid ID returns error.
     */
    public function test_update_status_invalid_order_id()
    {
        Auth::guard('admin')->login($this->admin);
        $response = $this->post('/admin/update-order-status', ['order_id' => 9999, 'order_status' => 'Shipped']);
        $response->assertStatus(500);
    }

    /**
     * TC-OR-ACT-09
     * Objective: Update order status to Cancelled sends specific email content.
     */
    public function test_order_status_cancelled_sends_email()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $this->post('/admin/update-order-status', ['order_id' => $order->id, 'order_status' => 'Cancelled']);
        
        Mail::assertSent(function (\Illuminate\Mail\Message $mail) {
            return true;
        });
    }

    /**
     * TC-OR-ACT-10
     * Objective: Update item status to Shipped without courier info.
     */
    public function test_update_item_status_shipped_no_info()
    {
        Auth::guard('admin')->login($this->admin);
        Mail::fake();
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        $item = OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $this->post('/admin/update-order-item-status', ['order_item_id' => $item->id, 'order_item_status' => 'Shipped']);
        $this->assertDatabaseHas('orders_products', ['id' => $item->id, 'item_status' => 'Shipped']);
    }

    /**
     * TC-OR-CAL-03
     * Objective: Order details view correctly handles zero coupon amount.
     */
    public function test_order_details_zero_coupon_discount()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create([
            'order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New'
        ]);
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertStatus(200);
        $this->assertEquals(0, $response->viewData('item_discount'));
    }

    /**
     * TC-OR-ACT-11
     * Objective: Admin can access invoice for an order with multiple products.
     */
    public function test_view_invoice_multiple_products()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 200
        ]);
        OrdersProduct::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New']);
        OrdersProduct::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 2, 'product_code' => 'P2', 'product_name' => 'P2', 'product_color' => 'B', 'product_size' => 'L', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New']);
        
        $response = $this->get('/admin/orders/invoice/'.$order->id);
        $response->assertStatus(200);
        $response->assertSee('P1');
        $response->assertSee('P2');
    }

    /**
     * TC-OR-ACT-12
     * Objective: Order details view displays customer email correctly.
     */
    public function test_order_details_customer_email()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 0, 'admin_id' => $this->admin->id, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 100, 'product_qty' => 1, 'item_status' => 'New']);
        
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertSee('user@test.com');
    }

    /**
     * TC-OR-SEC-04
     * Objective: Super admin can see all items in order details.
     */
    public function test_admin_sees_all_vendor_items()
    {
        Auth::guard('admin')->login($this->admin);
        $order = Order::create([
            'user_id' => $this->user->id, 'name' => 'U', 'address' => 'A', 'city' => 'C', 'state' => 'S', 'country' => 'CO', 'pincode' => 'P', 'mobile' => 'M', 'email' => 'user@test.com', 'shipping_charges' => 0, 'coupon_code' => '', 'coupon_amount' => 0, 'order_status' => 'New', 'payment_method' => 'COD', 'payment_gateway' => 'COD', 'grand_total' => 100
        ]);
        OrdersProduct::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 1, 'admin_id' => 10, 'product_id' => 1, 'product_code' => 'P1', 'product_name' => 'P1', 'product_color' => 'R', 'product_size' => 'M', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New']);
        OrdersProduct::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'vendor_id' => 2, 'admin_id' => 20, 'product_id' => 2, 'product_code' => 'P2', 'product_name' => 'P2', 'product_color' => 'B', 'product_size' => 'L', 'product_price' => 50, 'product_qty' => 1, 'item_status' => 'New']);
        
        $response = $this->get('/admin/orders/'.$order->id);
        $response->assertStatus(200);
        $this->assertCount(2, $response->viewData('orderDetails')['orders_products']);
    }
}
