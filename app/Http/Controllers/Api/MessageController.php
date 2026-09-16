<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends ApiController
{
    /** 我的消息列表（含未读数） */
    public function list(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $type = trim((string) $request->input('type'));
        $unreadOnly = $request->boolean('unread');
        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        $query = DB::table('app_messages')->where('account_id', $account->id);
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($unreadOnly) {
            $query->where('read', false);
        }
        $messages = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $unread = DB::table('app_messages')->where('account_id', $account->id)->where('read', false)->count();

        return response()->json([
            'ok' => true,
            'unread' => $unread,
            'items' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'content' => $m->content,
                'link' => $m->link,
                'project' => $m->project_name,
                'read' => (bool) $m->read,
                'time' => $m->created_at,
            ]),
        ]);
    }

    /** 未读消息数 */
    public function unreadCount(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $count = DB::table('app_messages')->where('account_id', $account->id)->where('read', false)->count();
        return response()->json(['ok' => true, 'unread' => $count]);
    }

    /** 标记单条已读 */
    public function markRead(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        $id = (int) $request->input('id');
        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => '参数错误'], 400);
        }
        DB::table('app_messages')->where('id', $id)->where('account_id', $account->id)->update(['read' => true, 'updated_at' => now()]);
        $unread = DB::table('app_messages')->where('account_id', $account->id)->where('read', false)->count();
        return response()->json(['ok' => true, 'unread' => $unread]);
    }

    /** 全部标记已读 */
    public function markAllRead(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }
        DB::table('app_messages')->where('account_id', $account->id)->where('read', false)->update(['read' => true, 'updated_at' => now()]);
        return response()->json(['ok' => true, 'unread' => 0]);
    }
}
