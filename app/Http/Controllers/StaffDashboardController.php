<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Inventory;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StaffDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('staff-dashboard', [
            'user' => $user,
            'assignedDeliveries' => Delivery::query()
                ->with('order.customer:customer_id,customer_name')
                ->where('assigned_to', $user->user_id)
                ->whereIn('delivery_status', ['Pending', 'Ongoing'])
                ->orderBy('delivery_date')
                ->get(),
            'pendingOrders' => Order::query()
                ->whereIn('order_status', ['Pending', 'Confirmed', 'Preparing'])
                ->count(),
            'lowStockCount' => Inventory::query()
                ->whereColumn('quantity', '<=', 'reorder_level')
                ->count(),
        ]);
    }
}
