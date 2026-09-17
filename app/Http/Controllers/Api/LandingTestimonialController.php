<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingTestimonialController extends Controller
{
    /**
     * Endpoint publik untuk menampilkan testimoni aktif di landing page.
     */
    public function index(Request $request): JsonResponse
    {
        $program = $request->query('program');

        $query = Testimonial::active()
            ->orderBy('order_no', 'asc')
            ->latest();

        if ($program) {
            $query->where('program', $program);
        }

        $testimonials = $query->get();

        return response()->json([
            'data' => $testimonials,
        ]);
    }
}
