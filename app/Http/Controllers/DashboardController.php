<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $recentOrders = Order::query()
            ->with('customer:customer_id,customer_name')
            ->latest('order_date')
            ->limit(5)
            ->get();

        $deliveries = Delivery::query()
            ->with('order.customer:customer_id,customer_name')
            ->whereIn('delivery_status', ['Pending', 'Ongoing'])
            ->orderBy('delivery_date')
            ->limit(4)
            ->get();

        return view('dashboard', [
            'user' => $request->user(),
            'activeProducts' => Product::query()->where('status', 'Active')->count(),
            'todayOrders' => Order::query()->whereDate('order_date', today())->count(),
            'todaySales' => Sale::query()->whereDate('sale_date', today())->sum('total_amount'),
            'lowStockItems' => Inventory::query()
                ->whereColumn('quantity', '<=', 'reorder_level')
                ->orderBy('quantity')
                ->limit(4)
                ->get(),
            'recentOrders' => $recentOrders,
            'deliveries' => $deliveries,
        ]);
    }
}
