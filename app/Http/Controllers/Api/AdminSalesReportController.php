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

        $kelasQuery = DB::table('kelas_orders as ko')
            ->join('kelas as k', 'k.id', '=', 'ko.kelas_id')
            ->whereIn('ko.status', ['paid', 'approved'])
            ->when($request->year,  fn($q, $y) => $q->whereRaw('YEAR(COALESCE(ko.paid_at, ko.updated_at)) = ?',  [$y]))
            ->when($request->month, fn($q, $m) => $q->whereRaw('MONTH(COALESCE(ko.paid_at, ko.updated_at)) = ?', [$m]));

        $rowsKelas = (clone $kelasQuery)
            ->selectRaw('
                "kelas"                                         AS type,
                k.name                                          AS product_name,
                YEAR(COALESCE(ko.paid_at, ko.updated_at))       AS year,
                MONTH(COALESCE(ko.paid_at, ko.updated_at))      AS month,
                MIN(DATE(COALESCE(ko.paid_at, ko.updated_at)))  AS period_start,
                SUM(1)                                          AS total_item_sold,
                COUNT(DISTINCT ko.id)                           AS order_count,
                k.price                                         AS average_price,
                SUM(ko.grand_total)                             AS total_sales
            ')
            ->groupByRaw('k.name, k.price, YEAR(COALESCE(ko.paid_at, ko.updated_at)), MONTH(COALESCE(ko.paid_at, ko.updated_at))')
            ->get();

        $rows = collect([...$rowsTryout, ...$rowsKelas])
            ->sortBy([
                ['year', 'desc'],
                ['month', 'desc'],
                ['product_name', 'asc'],
            ])
            ->values();

        $totalSalesTryout = (int) $rowsTryout->sum('total_sales');
        $totalItemSoldTryout = (int) $rowsTryout->sum('total_item_sold');
        $totalOrdersTryout = (int) (clone $baseQuery)->distinct('o.id')->count('o.id');

        $totalSalesKelas = (int) $rowsKelas->sum('total_sales');
        $totalItemSoldKelas = (int) $rowsKelas->sum('total_item_sold');
        $totalOrdersKelas = (int) (clone $kelasQuery)->distinct('ko.id')->count('ko.id');

        $totalSales = $totalSalesTryout + $totalSalesKelas;
        $totalItemSold = $totalItemSoldTryout + $totalItemSoldKelas;
        $totalOrders = $totalOrdersTryout + $totalOrdersKelas;

        // Tryout: Dev 60%, Amunisi 40%
        $amunisiTryoutRev = (int) round($totalSalesTryout * 0.4);
        $devTryoutRev = (int) round($totalSalesTryout * 0.6);

        // Kelas: Dev 20%, Amunisi 80%
        $amunisiKelasRev = (int) round($totalSalesKelas * 0.8);
        $devKelasRev = (int) round($totalSalesKelas * 0.2);

        $totalAmunisiRev = $amunisiTryoutRev + $amunisiKelasRev;
        $totalDevRev = $devTryoutRev + $devKelasRev;

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_sales' => $totalSales,
                'total_item_sold' => $totalItemSold,
                'order_count' => $totalOrders,
                'total_amunisi_revenue' => $totalAmunisiRev,
                'total_developer_revenue' => $totalDevRev,
                'tryout' => [
                    'total_sales' => $totalSalesTryout,
                    'total_item_sold' => $totalItemSoldTryout,
                    'order_count' => $totalOrdersTryout,
                    'amunisi_revenue' => $amunisiTryoutRev,
                    'developer_revenue' => $devTryoutRev,
                ],
                'kelas' => [
                    'total_sales' => $totalSalesKelas,
                    'total_item_sold' => $totalItemSoldKelas,
                    'order_count' => $totalOrdersKelas,
                    'amunisi_revenue' => $amunisiKelasRev,
                    'developer_revenue' => $devKelasRev,
                ]
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
