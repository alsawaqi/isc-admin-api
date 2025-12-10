<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\SupportTicketMessage;
use Illuminate\Support\Facades\Auth;

class SupportTicketAdminController extends Controller
{
    /**
     * GET /admin/support-tickets
     * Filters: status, type, q (search Subject/Reference), date range, customer_id, user_id
     * ?page=1&per_page=20&sort=-created_at
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'      => ['nullable', Rule::in(['open', 'pending', 'closed'])],
            'type'        => ['nullable', Rule::in(['support', 'feedback', 'return'])],
            'q'           => ['nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer'],
            'user_id'     => ['nullable', 'integer'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort'        => ['nullable', 'string'], // e.g. "-created_at", "subject"
        ]);

        $perPage = (int)($request->input('per_page', 20));
        $sort    = $request->input('sort', '-created_at');

        $q = SupportTicket::query()
            ->withCount('messages')
            ->with([
                // Attach lightweight user/customer names (if you maintain those tables)
                // Assuming Secx_User_Master_T has 'User_Name' and Customers_Master_T has 'Customer_Name'
                'messages' => fn($m) => $m->latest()->limit(1), // for last message preview in list

                'customer:id,Customer_Full_Name,Telephone',

            ]);

        if ($status = $request->input('status')) {
            $q->where('Ticket_Status', $status);
        }
        if ($type = $request->input('type')) {
            $q->where('Ticket_Type', $type);
        }
        if ($cid = $request->input('customer_id')) {
            $q->where('Customer_Id', $cid);
        }
        if ($uid = $request->input('user_id')) {
            $q->where('User_Id', $uid);
        }
        if ($term = trim((string)$request->input('q'))) {
            $q->where(function ($w) use ($term) {
                $w->where('Subject', 'like', "%{$term}%")
                    ->orWhere('Ticket_Reference', 'like', "%{$term}%");
            });
        }
        if ($from = $request->input('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        // Sorting
        // Support "-created_at" / "created_at" / "subject" / "-subject"
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        $sortable = [
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'subject'    => 'Subject',
            'status'     => 'Ticket_Status',
            'type'       => 'Ticket_Type',
        ];
        $q->orderBy($sortable[$col] ?? 'created_at', $dir);

        $paginator = $q->paginate($perPage);

        // Transform to a frontend-friendly shape
        $data = $paginator->getCollection()->map(function (SupportTicket $t) {
            $last = $t->messages->first(); // because we loaded latest()->limit(1)
            return [
                'id'               => $t->id,
                'reference'        => $t->Ticket_Reference,
                'subject'          => $t->Subject,
                'type'             => $t->Ticket_Type,
                'status'           => $t->Ticket_Status,
                'order_id'         => $t->Order_Id,
                'customer_id'      => $t->Customer_Id,
                'user_id'          => $t->User_Id,
                'messages_count'   => $t->messages_count,
                'last_message_at'  => $last?->created_at,
                'last_message_snippet' => $last?->Message_Body ? mb_strimwidth($last->Message_Body, 0, 120, '…') : null,
                'customer'              => $t->customer ? [
                    'id'         => $t->customer->id,
                    'full_name'  => $t->customer->Customer_Full_Name,
                    'telephone'  => $t->customer->Telephone,
                ] : null,
                'created_at'       => $t->created_at,
                'updated_at'       => $t->updated_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /admin/support-tickets/{id}
     * Returns ticket with full message thread (ascending), plus quick user/customer fields.
     */
    public function show(int $id): JsonResponse
    {
        $ticket = SupportTicket::query()
            ->with(['messages' => fn($q) => $q->orderBy('created_at', 'asc'), 'customer'])
            ->findOrFail($id);

        // You can join user/customer display info if needed
        $user = null;
        if ($ticket->User_Id) {
            $user = DB::table('Secx_User_Master_T')->where('User_Id', $ticket->User_Id)
                ->select('User_Id as id', 'User_Name as name', 'email as email')
                ->first();
        }
        $customer = null;
        if ($ticket->Customer_Id) {
            $customer = DB::table('Customers_Master_T')->where('id', $ticket->Customer_Id)
                ->select('id', 'Customer_Full_Name as name', 'Email_Address as email')
                ->first();
        }

        return response()->json([
            'ticket' => [
                'id'         => $ticket->id,
                'reference'  => $ticket->Ticket_Reference,
                'subject'    => $ticket->Subject,
                'type'       => $ticket->Ticket_Type,
                'status'     => $ticket->Ticket_Status,
                'order_id'   => $ticket->Order_Id,
                'user'       => $user,
                'customer'   => $customer,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
            ],
            'messages' => $ticket->messages->map(fn($m) => [
                'id'          => $m->id,
                'sender_type' => $m->Sender_Type,     // 'user' | 'support'
                'body'        => $m->Message_Body,
                'created_at'  => $m->created_at,
            ]),
            'customer' => $ticket->customer ? [
                'id'         => $ticket->customer->id,
                'full_name'  => $ticket->customer->Customer_Full_Name,
                'telephone'  => $ticket->customer->Telephone,
            ] : null,
        ]);
    }

    /**
     * POST /admin/support-tickets/{id}/reply
     * Body: { message_body: string, close?: bool, status?: 'open'|'pending'|'closed' }
     * Creates a support message and optionally updates status.
     */
    public function reply(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'message_body' => ['required', 'string'],
            'status'       => ['nullable', Rule::in(['open', 'pending', 'closed'])],
            'close'        => ['nullable', 'boolean'],
        ]);

        $ticket = SupportTicket::findOrFail($id);

        DB::transaction(function () use ($request, $ticket) {
            SupportTicketMessage::create([
                'Ticket_Id'   => $ticket->id,
                'Sender_Type' => 'support',
                'Message_Body' => $request->string('message_body'),
                'Admin_Id'    => Auth::id(), // assuming admin is authenticated
            ]);

            // If admin wants to close or set status explicitly
            if ($request->boolean('close') === true) {
                $ticket->Ticket_Status = 'closed';
            } elseif ($request->filled('status')) {
                $ticket->Ticket_Status = $request->input('status');
            } else {
                // Default behavior: set to pending when support replies (optional)
                if ($ticket->Ticket_Status === 'open') {
                    $ticket->Ticket_Status = 'pending';
                }
            }
            $ticket->touch();  // updates updated_at
            $ticket->save();
        });

        return response()->json(['message' => 'Reply posted.'], 201);
    }

    /**
     * PATCH /admin/support-tickets/{id}/status
     * Body: { status: 'open'|'pending'|'closed' }
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'closed'])],
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->Ticket_Status = $request->input('status');
        $ticket->save();

        return response()->json([
            'message' => 'Status updated.',
            'status'  => $ticket->Ticket_Status,
        ]);
    }

    // Optional – only if you want admins to delete a specific message.
    // public function deleteMessage(int $id, int $msgId): JsonResponse
    // {
    //     $ticket = SupportTicket::findOrFail($id);
    //     $msg = SupportTicketMessage::where('Ticket_Id', $ticket->id)->where('id', $msgId)->firstOrFail();
    //     $msg->delete();
    //     return response()->json(['message' => 'Message deleted.']);
    // }
}
