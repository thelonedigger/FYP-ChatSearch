<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * List all conversations for the authenticated user.
     * Returns lightweight summaries (no message bodies) for the sidebar.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()
            ->conversations()
            ->withCount('messages')
            ->with(['latestMessage' => fn ($q) => $q->select(
                'messages.id',
                'messages.conversation_id',
                'messages.content',
                'messages.created_at',
            )])
            ->get()
            ->map(fn (Conversation $c) => [
                'id'           => $c->id,
                'title'        => $c->title,
                'lastMessage'  => $c->latestMessage?->content
                    ? substr($c->latestMessage->content, 0, 100)
                    : '',
                'timestamp'    => ($c->latestMessage?->created_at ?? $c->updated_at)->toISOString(),
                'messageCount' => $c->messages_count,
            ]);

        return response()->json($conversations);
    }

    /**
     * Create a new conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => $request->input('title', 'New Conversation'),
        ]);

        return response()->json([
            'id'           => $conversation->id,
            'title'        => $conversation->title,
            'lastMessage'  => '',
            'timestamp'    => $conversation->created_at->toISOString(),
            'messageCount' => 0,
        ], 201);
    }

    /**
     * Retrieve a single conversation with all its messages.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id'       => $conversation->id,
            'title'    => $conversation->title,
            'messages' => $conversation->messages->map(fn ($m) => [
                'id'             => $m->id,
                'type'           => $m->role,
                'content'        => $m->content,
                'metadata'       => $m->metadata,
                'searchResponse' => $m->search_response,
                'created_at'     => $m->created_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Update conversation attributes (title).
     */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $conversation->update($request->only('title'));

        return response()->json([
            'id'    => $conversation->id,
            'title' => $conversation->title,
        ]);
    }

    /**
     * Delete a conversation and all its messages (cascaded by FK).
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $conversation->delete();

        return response()->json(null, 204);
    }

    /**
     * Append a message to a conversation.
     * Accepts both user and assistant messages — the frontend sends them
     * sequentially as the search flow completes.
     */
    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $request->validate([
            'role'            => 'required|string|in:user,assistant',
            'content'         => 'required|string',
            'metadata'        => 'sometimes|array',
            'search_response' => 'sometimes|array',
        ]);

        $message = $conversation->messages()->create($request->only(
            'role', 'content', 'metadata', 'search_response',
        ));
        $conversation->touch();

        return response()->json([
            'id'             => $message->id,
            'type'           => $message->role,
            'content'        => $message->content,
            'metadata'       => $message->metadata,
            'searchResponse' => $message->search_response,
            'created_at'     => $message->created_at->toISOString(),
        ], 201);
    }
}