<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminTestimonialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Testimonial::query();

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('role', 'like', "%{$search}%")
                  ->orWhere('quote', 'like', "%{$search}%");
            });
        }

        if ($request->filled('program')) {
            $query->where('program', $request->query('program'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $testimonials = $query->orderBy('order_no', 'asc')
            ->latest()
            ->get();

        return response()->json([
            'data' => $testimonials,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'role'        => ['required', 'string', 'max:255'],
            'program'     => ['required', 'string', 'in:UTBK-SNBT,CPNS'],
            'quote'       => ['required', 'string'],
            'rating'      => ['nullable', 'integer', 'min:1', 'max:5'],
            'avatar'      => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'avatar_bg'   => ['nullable', 'string', 'max:50'],
            'color_theme' => ['nullable', 'string', 'in:pink,yellow,mint,blue,lavender,cream'],
            'order_no'    => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('testimonials', 'public');
        }

        $testimonial = Testimonial::create([
            'name'        => $validated['name'],
            'role'        => $validated['role'],
            'program'     => $validated['program'],
            'quote'       => $validated['quote'],
            'rating'      => $validated['rating'] ?? 5,
            'avatar'      => $avatarPath,
            'avatar_bg'   => $validated['avatar_bg'] ?? null,
            'color_theme' => $validated['color_theme'] ?? 'pink',
            'order_no'    => $validated['order_no'] ?? 0,
            'is_active'   => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log('Testimonial', 'create', "Membuat testimoni baru '{$testimonial->name}' ({$testimonial->program})", $request->user(), $testimonial);

        return response()->json([
            'message' => 'Testimoni berhasil ditambahkan',
            'data'    => $testimonial,
        ], 201);
    }

    public function show(Testimonial $testimonial): JsonResponse
    {
        return response()->json([
            'data' => $testimonial,
        ]);
    }

    public function update(Request $request, Testimonial $testimonial): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'role'        => ['required', 'string', 'max:255'],
            'program'     => ['required', 'string', 'in:UTBK-SNBT,CPNS'],
            'quote'       => ['required', 'string'],
            'rating'      => ['nullable', 'integer', 'min:1', 'max:5'],
            'avatar'      => ['nullable'], // image file or string/null
            'avatar_bg'   => ['nullable', 'string', 'max:50'],
            'color_theme' => ['nullable', 'string', 'in:pink,yellow,mint,blue,lavender,cream'],
            'order_no'    => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $avatarPath = $testimonial->avatar;
        if ($request->hasFile('avatar')) {
            if ($testimonial->avatar && Storage::disk('public')->exists($testimonial->avatar)) {
                Storage::disk('public')->delete($testimonial->avatar);
            }
            $avatarPath = $request->file('avatar')->store('testimonials', 'public');
        } elseif ($request->exists('avatar') && $request->input('avatar') === null) {
            if ($testimonial->avatar && Storage::disk('public')->exists($testimonial->avatar)) {
                Storage::disk('public')->delete($testimonial->avatar);
            }
            $avatarPath = null;
        }

        $testimonial->update([
            'name'        => $validated['name'],
            'role'        => $validated['role'],
            'program'     => $validated['program'],
            'quote'       => $validated['quote'],
            'rating'      => $validated['rating'] ?? $testimonial->rating,
            'avatar'      => $avatarPath,
            'avatar_bg'   => $validated['avatar_bg'] ?? $testimonial->avatar_bg,
            'color_theme' => $validated['color_theme'] ?? $testimonial->color_theme,
            'order_no'    => $validated['order_no'] ?? $testimonial->order_no,
            'is_active'   => $validated['is_active'] ?? $testimonial->is_active,
        ]);

        AuditLogger::log('Testimonial', 'update', "Memperbarui testimoni '{$testimonial->name}'", $request->user(), $testimonial);

        return response()->json([
            'message' => 'Testimoni berhasil diperbarui',
            'data'    => $testimonial,
        ]);
    }

    public function destroy(Request $request, Testimonial $testimonial): JsonResponse
    {
        if ($testimonial->avatar && Storage::disk('public')->exists($testimonial->avatar)) {
            Storage::disk('public')->delete($testimonial->avatar);
        }

        $name = $testimonial->name;
        $testimonial->delete();

        AuditLogger::log('Testimonial', 'delete', "Menghapus testimoni '{$name}'", $request->user());

        return response()->json([
            'message' => 'Testimoni berhasil dihapus',
        ]);
    }
}
