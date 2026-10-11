<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate(min(100, $request->integer('per_page', 25)));

        $tags = DB::table('contact_tags')->whereIn('contact_id', $contacts->pluck('id'))->get()->groupBy('contact_id');

        return response()->json([
            'data' => $contacts->getCollection()->map(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'telegram_chat_id' => $c->telegram_chat_id,
                'marketing_consent' => $c->marketing_consent,
                'tags' => $tags->get($c->id, collect())->pluck('tag')->sort()->values(),
            ]),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total()],
        ]);
    }

    public function tags(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('contact_tags')->selectRaw('tag, count(*) as total')->groupBy('tag')->orderBy('tag')->get(),
        ]);
    }
}
