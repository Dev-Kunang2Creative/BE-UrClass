<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSalesReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $baseQuery = DB::table('orders as o')
            ->leftJoin('order_items as oi', 'o.id', '=', 'oi.order_id')
            ->leftJoin('packages as p', 'oi.package_id', '=', 'p.id')
            ->whereIn('o.status', ['paid', 'approved'])
            ->when($request->year,  fn($q, $y) => $q->whereRaw('YEAR(COALESCE(o.paid_at, o.approved_at, o.updated_at)) = ?',  [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(COALESCE(o.paid_at, o.approved_at, o.updated_at)) = ?', [$m]));

        $rowsTryout = (clone $baseQuery)
            ->selectRaw('
                "tryout"                                                              AS type,
                COALESCE(oi.package_name_snapshot, "Order Tanpa Item")                AS product_name,
                YEAR(COALESCE(o.paid_at, o.approved_at, o.updated_at))                AS year,
                MONTH(COALESCE(o.paid_at, o.approved_at, o.updated_at))               AS month,
                MIN(DATE(COALESCE(o.paid_at, o.approved_at, o.updated_at)))           AS period_start,
                SUM(COALESCE(oi.qty, 0))                                              AS total_item_sold,
                COUNT(DISTINCT o.id)                                                  AS order_count,
                COALESCE(oi.price, o.grand_total)                                     AS average_price,
                SUM(COALESCE(oi.subtotal, o.grand_total))                             AS total_sales
            ')
            ->groupByRaw('COALESCE(oi.package_name_snapshot, "Order Tanpa Item"), COALESCE(oi.price, o.grand_total), YEAR(COALESCE(o.paid_at, o.approved_at, o.updated_at)), MONTH(COALESCE(o.paid_at, o.approved_at, o.updated_at))')
            ->get();

        $rows = $rowsTryout
            ->sortBy([
                ['year', 'desc'],
                ['month', 'desc'],
                ['product_name', 'asc'],
            ])
            ->values();

        $totalSales = (int) $rowsTryout->sum('total_sales');
        $totalItemSold = (int) $rowsTryout->sum('total_item_sold');
        $totalOrders = (int) (clone $baseQuery)->distinct('o.id')->count('o.id');

        // Satu-satunya produk yang dijual adalah paket tiket tryout. Fitur kelas
        // sudah dihapus, jadi laporan ini tidak lagi memecah pendapatan per
        // jenis produk - tidak ada jenis kedua untuk dibandingkan, dan angka
        // "Pendapatan Tryout" akan sama persis dengan totalnya.
        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_sales' => $totalSales,
                'total_item_sold' => $totalItemSold,
                'order_count' => $totalOrders,
                'average_order_value' => $totalOrders > 0
                    ? (int) round($totalSales / $totalOrders)
                    : 0,
            ],
        ]);
    }

    public function feeTryout(Request $request): JsonResponse
    {
        $feePerParticipant = 6000;

        $rows = DB::table('user_tryout_access as uta')
            ->join('tryouts as t', 't.id', '=', 'uta.tryout_id')
            ->when($request->year, fn($q, $y) => $q->whereRaw('YEAR(uta.granted_at) = ?', [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(uta.granted_at) = ?', [$m]))
            ->where('t.is_free', false)
            ->selectRaw('
            t.id                        AS tryout_id,
            t.title                     AS tryout_name,
            YEAR(uta.granted_at)        AS year,
            MONTH(uta.granted_at)       AS month,
            MIN(DATE(uta.granted_at))   AS period_start,
            COUNT(DISTINCT uta.user_id) AS participant_count,
            COUNT(uta.id)               AS access_count
        ')
            ->groupByRaw('t.id, t.title, YEAR(uta.granted_at), MONTH(uta.granted_at)')
            ->orderByRaw('YEAR(uta.granted_at) DESC, MONTH(uta.granted_at) DESC, t.title ASC')
            ->get()
            ->map(function ($row) use ($feePerParticipant) {
                $row->participant_count = (int) $row->participant_count;
                $row->access_count = (int) $row->access_count;
                $row->total_fee = $row->participant_count * $feePerParticipant;

                return $row;
            });

        $totalFee = (int) $rows->sum('total_fee');
        $totalParticipants = (int) $rows->sum('participant_count');
        $tryoutCount = $rows->pluck('tryout_id')->unique()->count();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'fee_per_participant' => $feePerParticipant,
                'total_fee' => $totalFee,
                'total_participants' => $totalParticipants,
                'tryout_count' => $tryoutCount,
                'average_fee_per_tryout' => $tryoutCount > 0 ? (int) round($totalFee / $tryoutCount) : 0,
            ],
        ]);
    }
}
