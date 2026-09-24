<?php


namespace App\Http\Controllers\Api\V1;


use App\Http\Controllers\Controller;
use App\Services\MessageContentFilter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;


class MessageController extends Controller
{
    private const DELETED_MESSAGE_MARKER =
        '__IRIS_SAM_DELETED_MESSAGE__';

    private const DELETED_MESSAGE_TEXT =
        'This message was deleted';

    /**
     * Resolve the reusable server-side message content filter.
     *
     * The controller is kept constructor-free so existing routes/tests that
     * instantiate it are not affected.
     */
    private function messageContentFilter(): MessageContentFilter
    {
        return app(
            MessageContentFilter::class,
        );
    }


    /**
     * Keep the moderation rejection response consistent for new messages and
     * conversation replies.
     */
    private function contentNotAllowedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,

            'message' =>
                'Your message contains content that is not allowed under the IRIS-SAMS Community Guidelines. Please revise your message and try again.',

            'code' =>
                'CONTENT_NOT_ALLOWED',
        ], 422);
    }


    /**
     * Apply a common collation when comparing ID columns.
     *
     * Some older AdmaPro databases use utf8_general_ci while
     * other related tables use utf8_unicode_ci.
     */
    private function collatedColumn(
        string $column,
    ) {
        return DB::raw(
            $column . ' COLLATE utf8_unicode_ci'
        );
    }


    public function fetchMessageModule(
        Request $request,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);

        try {
            $userId =
                $validated['user_id'];

            $connection =
                $this->schoolConnection(
                    $request,
                );

            $personExists =
                $connection
                    ->table('person')
                    ->where(
                        'id',
                        $userId,
                    )
                    ->exists();

            $loginExists =
                $connection
                    ->table('login')
                    ->where(
                        'id',
                        $userId,
                    )
                    ->exists();

            if (
                !$personExists
                && !$loginExists
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'User not found.',
                ], 404);
            }

            $messages =
                $connection
                    ->table('inbox')
                    ->leftJoin(
                        'person as sender_person',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.sender_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'sender_person.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'login as sender_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.sender_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'sender_login.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'person as recipient_person',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_person.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'login as recipient_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_login.id',
                                ),
                            );
                        },
                    )
                    ->where(
                        function ($query) use (
                            $userId,
                        ) {
                            $query
                                ->where(
                                    'inbox.sender_id',
                                    $userId,
                                )
                                ->orWhere(
                                    'inbox.recipient_id',
                                    $userId,
                                );
                        },
                    )
                    ->select([
                        'inbox.id as inbox_id',
                        'inbox.login_id',
                        'inbox.sender_id',
                        'inbox.recipient_id',
                        'inbox.recipient_type',
                        'inbox.subj_inbox',
                        'inbox.date_inbox',
                        'inbox.date_read',
                        'inbox.last_update',
                        'inbox.content_inbox',

                        'sender_person.id as sender_person_id',
                        'sender_person.gender as sender_gender',
                        'sender_person.photo_file as sender_photo_file',
                        'sender_login.id as sender_login_id',

                        'recipient_person.id as recipient_person_id',
                        'recipient_person.gender as recipient_gender',
                        'recipient_person.photo_file as recipient_photo_file',
                        'recipient_login.id as recipient_login_id',

                        DB::raw("
                            COALESCE(
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            sender_person.fname,
                                            sender_person.mname,
                                            sender_person.lname
                                        )
                                    ),
                                    ''
                                ),
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            sender_login.fname,
                                            sender_login.lname
                                        )
                                    ),
                                    ''
                                ),
                                CONCAT(
                                    'User ',
                                    LEFT(
                                        inbox.sender_id,
                                        8
                                    )
                                )
                            ) AS sender_full_name
                        "),

                        DB::raw("
                            COALESCE(
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_person.fname,
                                            recipient_person.mname,
                                            recipient_person.lname
                                        )
                                    ),
                                    ''
                                ),
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_login.fname,
                                            recipient_login.lname
                                        )
                                    ),
                                    ''
                                ),
                                CASE
                                    WHEN recipient_person.id IS NOT NULL
                                        THEN CONCAT(
                                            'Cadet ',
                                            LEFT(
                                                inbox.recipient_id,
                                                8
                                            )
                                        )
                                    WHEN recipient_login.id IS NOT NULL
                                        THEN CONCAT(
                                            'Administrator ',
                                            LEFT(
                                                inbox.recipient_id,
                                                8
                                            )
                                        )
                                    WHEN LOWER(
                                        COALESCE(
                                            inbox.recipient_type,
                                            ''
                                        )
                                    ) IN (
                                        'cadet',
                                        'student',
                                        'person'
                                    )
                                        THEN CONCAT(
                                            'Cadet ',
                                            LEFT(
                                                inbox.recipient_id,
                                                8
                                            )
                                        )
                                    WHEN LOWER(
                                        COALESCE(
                                            inbox.recipient_type,
                                            ''
                                        )
                                    ) IN (
                                        'admin',
                                        'administrator',
                                        'login'
                                    )
                                        THEN CONCAT(
                                            'Administrator ',
                                            LEFT(
                                                inbox.recipient_id,
                                                8
                                            )
                                        )
                                    ELSE CONCAT(
                                        'User ',
                                        LEFT(
                                            inbox.recipient_id,
                                            8
                                        )
                                    )
                                END
                            ) AS recipient_full_name
                        "),
                    ])
                    ->selectSub(
                        function ($query) use (
                            $userId,
                        ) {
                            $query
                                ->from('inbox_reply')
                                ->selectRaw('COUNT(*)')
                                ->whereColumn(
                                    'inbox_reply.inbox_id',
                                    'inbox.id',
                                )
                                ->where(
                                    'inbox_reply.recipient_id',
                                    $userId,
                                )
                                ->where(
                                    function ($query) {
                                        $query
                                            ->whereNull(
                                                'inbox_reply.date_read',
                                            )
                                            ->orWhere(
                                                'inbox_reply.date_read',
                                                '',
                                            );
                                    },
                                );
                        },
                        'unread_reply_count',
                    )
                    ->orderByDesc(
                        'inbox.last_update',
                    )
                    ->orderByDesc(
                        'inbox.date_inbox',
                    )
                    ->get()
                    ->map(
                        function ($message) use (
                            $request,
                            $userId,
                        ) {
                            $currentUserIsRecipient =
                                (string) $message->recipient_id ===
                                (string) $userId;

                            if ($currentUserIsRecipient) {
                                $message->recipient_full_name =
                                    $message->sender_full_name;

                                $message->recipient_gender =
                                    $message->sender_gender;

                                $message->recipient_photo_url =
                                    $message->sender_person_id
                                        ? $this
                                            ->buildStudentPhotoUrl(
                                                $request,
                                                $message->sender_photo_file,
                                            )
                                        : null;

                                $message->recipient_account_type =
                                    $message->sender_person_id
                                        ? 'Cadet'
                                        : (
                                            $message->sender_login_id
                                                ? 'Admin'
                                                : 'User'
                                        );
                            } else {
                                $message->recipient_photo_url =
                                    $message->recipient_person_id
                                        ? $this
                                            ->buildStudentPhotoUrl(
                                                $request,
                                                $message->recipient_photo_file,
                                            )
                                        : null;

                                $message->recipient_account_type =
                                    $message->recipient_person_id
                                        ? 'Cadet'
                                        : (
                                            $message->recipient_login_id
                                                ? 'Admin'
                                                : (
                                                    in_array(
                                                        strtolower(
                                                            (string) (
                                                                $message->recipient_type
                                                                ?? ''
                                                            ),
                                                        ),
                                                        [
                                                            'cadet',
                                                            'student',
                                                            'person',
                                                        ],
                                                        true,
                                                    )
                                                        ? 'Cadet'
                                                        : 'Admin'
                                                )
                                        );
                            }

                            $message->has_unread =
                                (
                                    $currentUserIsRecipient
                                    && trim(
                                        (string) (
                                            $message->date_read
                                            ?? ''
                                        ),
                                    ) === ''
                                )
                                || (int) (
                                    $message->unread_reply_count
                                    ?? 0
                                ) > 0;

                            $message->is_deleted =
                                $this->isDeletedMessage(
                                    $message->content_inbox
                                    ?? null,
                                );

                            if ($message->is_deleted) {
                                $message->content_inbox =
                                    self::DELETED_MESSAGE_TEXT;
                            }

                            unset(
                                $message->unread_reply_count,
                                $message->sender_full_name,
                                $message->sender_gender,
                                $message->sender_photo_file,
                                $message->sender_person_id,
                                $message->sender_login_id,
                                $message->recipient_photo_file,
                                $message->recipient_person_id,
                                $message->recipient_login_id,
                            );

                            return $message;
                        },
                    )
                    ->values();

            return response()->json([
                'success' => true,
                'message' =>
                    'Messages fetched successfully.',
                'data' =>
                    $messages,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'success' => false,
                'message' =>
                    'Unable to fetch messages.',
                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    public function fetchMessageReplies(
        Request $request,
        string $inboxId,
    ): JsonResponse {
        try {
            $inbox =
                $this->schoolConnection($request)
                    ->table('inbox')
                    ->leftJoin(
                        'login as sender_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.sender_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'sender_login.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'person as recipient_person',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_person.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'login as recipient_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_login.id',
                                ),
                            );
                        },
                    )
                    ->where(
                        'inbox.id',
                        $inboxId,
                    )
                    ->select([
                        'inbox.id as inbox_id',
                        'inbox.login_id',
                        'inbox.sender_id',
                        'sender_login.id as sender_login_id',
                        'inbox.recipient_id',
                        'inbox.subj_inbox',
                        'inbox.content_inbox',
                        'inbox.date_inbox',
                        'inbox.date_read',


                        DB::raw("
                            COALESCE(
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_person.fname,
                                            recipient_person.mname,
                                            recipient_person.lname
                                        )
                                    ),
                                    ''
                                ),
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_login.fname,
                                            recipient_login.lname
                                        )
                                    ),
                                    ''
                                )
                            ) AS recipient_full_name
                        "),
                    ])
                    ->first();


            if (!$inbox) {
                return response()->json([
                    'success' => false,


                    'message' =>
                        'Inbox message not found.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | Mark Original Inbox Message As Read
            |--------------------------------------------------------------------------
            |
            | Background synchronization calls this endpoint without user_id, so
            | merely caching a thread never marks it as read. The interactive
            | message screen includes user_id. Only the original recipient may
            | update inbox.date_read.
            |
            */

            $moderation = [
                'available' => false,
                'is_blocked' => false,
                'other_user_id' => null,
            ];


            $viewerId =
                trim(
                    (string) $request->query(
                        'user_id',
                        '',
                    ),
                );


            if ($viewerId !== '') {
                $isSender =
                    (string) $inbox->sender_id ===
                    (string) $viewerId;


                $isRecipient =
                    (string) $inbox->recipient_id ===
                    (string) $viewerId;


                if (
                    !$isSender
                    && !$isRecipient
                ) {
                    return response()->json([
                        'success' => false,


                        'message' =>
                            'You are not a participant in this conversation.',
                    ], 403);
                }


                $otherUserId =
                    $isSender
                        ? (string) $inbox->recipient_id
                        : (string) $inbox->sender_id;


                $moderation['other_user_id'] =
                    $otherUserId !== ''
                        ? $otherUserId
                        : null;


                /*
                 * Moderation tables were added without changing the existing
                 * messaging schema. If a school database has not received the
                 * moderation tables yet, normal conversation loading must keep
                 * working and the frontend simply hides moderation actions.
                 */
                try {
                    $moderation['is_blocked'] =
                        $otherUserId !== ''
                        && $this->schoolConnection($request)
                            ->table('message_blocks')
                            ->where(
                                'blocker_id',
                                $viewerId,
                            )
                            ->where(
                                'blocked_user_id',
                                $otherUserId,
                            )
                            ->exists();


                    $moderation['available'] =
                        true;
                } catch (Throwable $moderationThrowable) {
                    report(
                        $moderationThrowable,
                    );
                }


                if (
                    $isRecipient
                    && trim(
                        (string) (
                            $inbox->date_read
                            ?? ''
                        ),
                    ) === ''
                ) {
                    $readTimestamp =
                        now()->format(
                            'Y-m-d H:i:s',
                        );


                    $this->schoolConnection($request)
                        ->table('inbox')
                        ->where(
                            'id',
                            $inboxId,
                        )
                        ->where(
                            'recipient_id',
                            $viewerId,
                        )
                        ->where(
                            function ($query) {
                                $query
                                    ->whereNull(
                                        'date_read',
                                    )
                                    ->orWhere(
                                        'date_read',
                                        '',
                                    );
                            },
                        )
                        ->update([
                            'date_read' =>
                                $readTimestamp,
                        ]);


                    $inbox->date_read =
                        $readTimestamp;
                }

                /*
                 * A conversation can become unread again when the other
                 * participant sends a reply after the original inbox message
                 * was already read. Mark every unread reply addressed to the
                 * current viewer as read when the thread is actually opened.
                 */
                $this->schoolConnection($request)
                    ->table('inbox_reply')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->where(
                        'recipient_id',
                        $viewerId,
                    )
                    ->where(
                        function ($query) {
                            $query
                                ->whereNull(
                                    'date_read',
                                )
                                ->orWhere(
                                    'date_read',
                                    '',
                                );
                        },
                    )
                    ->update([
                        'date_read' =>
                            now()->format(
                                'Y-m-d H:i:s',
                            ),
                    ]);
            }


            $messageStorage =
                $this->messageStorage(
                    $request,
                );


            $attachmentBaseUrl =
                rtrim(
                    $messageStorage[
                        'upload_url'
                    ],
                    '/',
                );


            $attachments =
                $this->schoolConnection($request)
                    ->table('inbox_files')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->select([
                        'id',
                        'inbox_id',
                        'filename',
                        'order_no',
                        'file_desc',
                    ])
                    ->orderBy(
                        'order_no',
                    )
                    ->get()
                    ->map(
                        function (
                            $attachment,
                        ) use (
                            $attachmentBaseUrl,
                        ) {
                            $fileName =
                                trim(
                                    (string) (
                                        $attachment->filename
                                        ?? ''
                                    ),
                                );


                            $attachment->url =
                                $attachmentBaseUrl !== ''
                                && $fileName !== ''
                                    ? $attachmentBaseUrl
                                        . '/'
                                        . rawurlencode(
                                            $fileName,
                                        )
                                    : null;


                            return $attachment;
                        },
                    );


            $inboxIsDeleted =
                $this->isDeletedMessage(
                    $inbox->content_inbox
                    ?? null,
                );

            if ($inboxIsDeleted) {
                $attachments =
                    collect();
            }


            $replies =
                $this->schoolConnection($request)
                    ->table('inbox_reply')
                    ->leftJoin(
                        'person as sender_person',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox_reply.sender_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'sender_person.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'login as sender_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox_reply.sender_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'sender_login.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'person as recipient_person',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox_reply.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_person.id',
                                ),
                            );
                        },
                    )
                    ->leftJoin(
                        'login as recipient_login',
                        function ($join) {
                            $join->on(
                                $this->collatedColumn(
                                    'inbox_reply.recipient_id',
                                ),
                                '=',
                                $this->collatedColumn(
                                    'recipient_login.id',
                                ),
                            );
                        },
                    )
                    ->where(
                        'inbox_reply.inbox_id',
                        $inboxId,
                    )
                    ->select([
                        'inbox_reply.id as reply_id',
                        'inbox_reply.inbox_id',
                        'inbox_reply.reply_msg',
                        'inbox_reply.reply_date',
                        'inbox_reply.date_read',
                        'inbox_reply.sender_id',
                        'inbox_reply.recipient_id',


                        DB::raw("
                            COALESCE(
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            sender_person.fname,
                                            sender_person.mname,
                                            sender_person.lname
                                        )
                                    ),
                                    ''
                                ),
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            sender_login.fname,
                                            sender_login.lname
                                        )
                                    ),
                                    ''
                                )
                            ) AS sender_full_name
                        "),


                        DB::raw("
                            COALESCE(
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_person.fname,
                                            recipient_person.mname,
                                            recipient_person.lname
                                        )
                                    ),
                                    ''
                                ),
                                NULLIF(
                                    TRIM(
                                        CONCAT_WS(
                                            ' ',
                                            recipient_login.fname,
                                            recipient_login.lname
                                        )
                                    ),
                                    ''
                                )
                            ) AS recipient_full_name
                        "),
                    ])
                    ->orderBy(
                        'inbox_reply.reply_date',
                    )
                    ->get();


            $replyIds =
                $replies
                    ->pluck(
                        'reply_id',
                    )
                    ->filter()
                    ->values();


            $replyAttachmentRows =
                $replyIds->isEmpty()
                    ? collect()
                    : $this->schoolConnection($request)
                        ->table('inbox_reply_files')
                        ->whereIn(
                            'inbox_reply_id',
                            $replyIds,
                        )
                        ->select([
                            'id',
                            'inbox_reply_id',
                            'filename',
                            'order_no',
                            'file_desc',
                        ])
                        ->orderBy(
                            'order_no',
                        )
                        ->get();


            $replyAttachmentsByReply =
                $replyAttachmentRows
                    ->map(
                        function (
                            $attachment,
                        ) use (
                            $attachmentBaseUrl,
                        ) {
                            $fileName =
                                trim(
                                    (string) (
                                        $attachment->filename
                                        ?? ''
                                    ),
                                );


                            $attachment->url =
                                $attachmentBaseUrl !== ''
                                && $fileName !== ''
                                    ? $attachmentBaseUrl
                                        . '/'
                                        . rawurlencode(
                                            $fileName,
                                        )
                                    : null;


                            return $attachment;
                        },
                    )
                    ->groupBy(
                        'inbox_reply_id',
                    );


            $replies =
                $replies
                    ->map(
                        function (
                            $reply,
                        ) use (
                            $replyAttachmentsByReply,
                        ) {
                            $reply->is_deleted =
                                $this->isDeletedMessage(
                                    $reply->reply_msg
                                    ?? null,
                                );

                            $reply->attachments =
                                $reply->is_deleted
                                    ? collect()
                                    : $replyAttachmentsByReply
                                        ->get(
                                            $reply->reply_id,
                                            collect(),
                                        )
                                        ->values();

                            if ($reply->is_deleted) {
                                $reply->reply_msg =
                                    self::DELETED_MESSAGE_TEXT;
                            }


                            return $reply;
                        },
                    );


            return response()->json([
                'success' => true,


                'message' =>
                    'Message replies fetched successfully.',


                'data' => [
                    'inbox' => [
                        'inbox_id' =>
                            $inbox->inbox_id,


                        'login_id' =>
                            $inbox->login_id,


                        'sender_id' =>$inbox->sender_id,


                        'sender_login_id' =>
                            $inbox->sender_login_id,


                        'recipient_id' =>
                            $inbox->recipient_id,


                        'recipient_full_name' =>
                            $inbox->recipient_full_name,


                        'subject' =>
                            $inbox->subj_inbox,


                        'content' =>
                            $inboxIsDeleted
                                ? self::DELETED_MESSAGE_TEXT
                                : $inbox->content_inbox,


                        'is_deleted' =>
                            $inboxIsDeleted,


                        'date' =>
                            $inbox->date_inbox,


                        'date_read' =>
                            $inbox->date_read,
                    ],


                    'attachments' =>
                        $attachments,

                    'replies' =>
                        $replies,

                    'moderation' =>
                        $moderation,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);


            return response()->json([
                'success' => false,


                'message' =>
                    'Unable to fetch message replies.',


                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }

    public function storeMessageReply(
        Request $request,
        string $inboxId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'reply_msg' => [
                    'nullable',
                    'string',
                ],

                'files' => [
                    'nullable',
                    'array',
                ],

                'files.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
                    'max:20480',
                ],
            ]);


        $connection =
            $this->schoolConnection($request);


        $uploadedFiles =
            $request->file(
                'files',
                [],
            );


        if (!is_array($uploadedFiles)) {
            $uploadedFiles =
                $uploadedFiles
                    ? [$uploadedFiles]
                    : [];
        }


        $uploadedFiles =
            array_values(
                array_filter(
                    $uploadedFiles,
                    static function ($uploadedFile) {
                        return
                            $uploadedFile
                            && $uploadedFile->isValid();
                    },
                ),
            );


        $replyMessage =
            trim(
                (string) (
                    $validated['reply_msg']
                    ?? ''
                ),
            );


        if (
            $replyMessage === ''
            && count($uploadedFiles) === 0
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'A reply message or attachment is required.',
            ], 422);
        }


        if ($replyMessage !== '') {
            $moderationResult =
                $this
                    ->messageContentFilter()
                    ->inspect(
                        $replyMessage,
                    );


            if (!$moderationResult['allowed']) {
                return
                    $this
                        ->contentNotAllowedResponse();
            }
        }


        try {
            /*
            |--------------------------------------------------------------------------
            | Find Original Message
            |--------------------------------------------------------------------------
            */


            $inbox =
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'recipient_id',
                    ])
                    ->first();


            if (!$inbox) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Inbox message not found.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Sender
            |--------------------------------------------------------------------------
            */


            $senderId =
                $validated['user_id'];


            $senderExistsInPerson =
                $connection
                    ->table('person')
                    ->where(
                        'id',
                        $senderId,
                    )
                    ->exists();


            $senderExistsInLogin =
                $connection
                    ->table('login')
                    ->where(
                        'id',
                        $senderId,
                    )
                    ->exists();


            if (
                !$senderExistsInPerson
                && !$senderExistsInLogin
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Sender not found.',
                ], 404);
            }


            /*
            |--------------------------------------------------------------------------
            | Determine Other Conversation Participant
            |--------------------------------------------------------------------------
            */


            if (
                $senderId ===
                $inbox->sender_id
            ) {
                $recipientId =
                    $inbox->recipient_id;
            } elseif (
                $senderId ===
                $inbox->recipient_id
            ) {
                $recipientId =
                    $inbox->sender_id;
            } else {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'You are not a participant in this conversation.',
                ], 403);
            }


            if (
                !$recipientId
                || trim(
                    (string) $recipientId,
                ) === ''
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Conversation recipient is unavailable.',
                ], 422);
            }


            /*
             * If moderation tables exist for the selected school, enforce
             * blocks in either direction. Databases that have not yet received
             * the moderation tables keep their existing messaging behavior.
             */
            try {
                $hasMessageBlock =
                    $connection
                        ->table('message_blocks')
                        ->where(
                            function ($query) use (
                                $senderId,
                                $recipientId,
                            ) {
                                $query
                                    ->where(
                                        'blocker_id',
                                        $senderId,
                                    )
                                    ->where(
                                        'blocked_user_id',
                                        $recipientId,
                                    );
                            },
                        )
                        ->orWhere(
                            function ($query) use (
                                $senderId,
                                $recipientId,
                            ) {
                                $query
                                    ->where(
                                        'blocker_id',
                                        $recipientId,
                                    )
                                    ->where(
                                        'blocked_user_id',
                                        $senderId,
                                    );
                            },
                        )
                        ->exists();


                if ($hasMessageBlock) {
                    return response()->json([
                        'success' => false,

                        'message' =>
                            'Messaging is unavailable because one of these users has blocked the other.',
                    ], 403);
                }
            } catch (Throwable $moderationThrowable) {
                report(
                    $moderationThrowable,
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Current School FTP Configuration
            |--------------------------------------------------------------------------
            */


            $hasAttachments =
                count($uploadedFiles) > 0;


            $messageStorage =
                $this->messageStorage(
                    $request,
                );


            $ftpHost =
                $messageStorage[
                    'ftp_host'
                ];


            $ftpUsername =
                $messageStorage[
                    'ftp_username'
                ];


            $ftpPassword =
                $messageStorage[
                    'ftp_password'
                ];


            $ftpPort =
                $messageStorage[
                    'ftp_port'
                ];


            $ftpRoot =
                $messageStorage[
                    'ftp_root'
                ];


            if ($hasAttachments) {
                if (
                    $ftpHost === ''
                    || $ftpUsername === ''
                    || $ftpPassword === ''
                    || $ftpPort <= 0
                ) {
                    return response()->json([
                        'success' => false,

                        'message' =>
                            'School message attachment storage is not configured.',
                    ], 500);
                }


                if (!function_exists('ftp_connect')) {
                    return response()->json([
                        'success' => false,

                        'message' =>
                            'FTP support is not available on the API server.',
                    ], 500);
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Create Reply
            |--------------------------------------------------------------------------
            */


            $replyId =
                (string) Str::uuid();


            $timestamp =
                now()->format(
                    'Y-m-d H:i:s',
                );


            $uploadedRemoteFiles = [];


            $ftpConnection =
                null;


            $connection
                ->beginTransaction();


            try {
                $connection
                    ->table('inbox_reply')
                    ->insert([
                        'id' =>
                            $replyId,

                        'inbox_id' =>
                            $inboxId,

                        'reply_msg' =>
                            $replyMessage,

                        'reply_date' =>
                            $timestamp,

                        'date_read' =>
                            null,

                        'sender_id' =>
                            $senderId,

                        'recipient_id' =>
                            $recipientId,
                    ]);


                /*
                |--------------------------------------------------------------------------
                | Reply Attachments
                |--------------------------------------------------------------------------
                */


                if ($hasAttachments) {
                    $ftpConnection =
                        @ftp_connect(
                            $ftpHost,
                            $ftpPort,
                            30,
                        );


                    if (!$ftpConnection) {
                        throw new \RuntimeException(
                            'Unable to connect to the school file server.',
                        );
                    }


                    $loggedIn =
                        @ftp_login(
                            $ftpConnection,
                            $ftpUsername,
                            $ftpPassword,
                        );


                    if (!$loggedIn) {
                        throw new \RuntimeException(
                            'Unable to authenticate with the school file server.',
                        );
                    }


                    @ftp_pasv(
                        $ftpConnection,
                        true,
                    );


                    if (
                        !@ftp_chdir(
                            $ftpConnection,
                            $ftpRoot,
                        )
                    ) {
                        throw new \RuntimeException(
                            'Message upload directory does not exist on the school file server: '
                            . $ftpRoot,
                        );
                    }


                    foreach (
                        $uploadedFiles as
                        $uploadedFile
                    ) {
                        $extension =
                            strtolower(
                                $uploadedFile
                                    ->getClientOriginalExtension()
                                ?: $uploadedFile
                                    ->extension()
                                ?: 'bin',
                            );


                        $fileName =
                            'message_reply_'
                            . $replyId
                            . '_'
                            . Str::lower(
                                Str::random(8),
                            )
                            . '.'
                            . $extension;


                        $originalFileName =
                            $uploadedFile
                                ->getClientOriginalName();


                        $localPath =
                            $uploadedFile
                                ->getRealPath();


                        if (
                            !$localPath
                            || !is_file($localPath)
                        ) {
                            throw new \RuntimeException(
                                'The uploaded reply attachment could not be read.',
                            );
                        }


                        $stream =
                            @fopen(
                                $localPath,
                                'rb',
                            );


                        if (!$stream) {
                            throw new \RuntimeException(
                                'Unable to read the uploaded reply attachment.',
                            );
                        }


                        try {
                            $uploaded =
                                @ftp_fput(
                                    $ftpConnection,
                                    $fileName,
                                    $stream,
                                    FTP_BINARY,
                                );
                        } finally {
                            fclose(
                                $stream,
                            );
                        }


                        if (!$uploaded) {
                            throw new \RuntimeException(
                                'Unable to upload reply attachment to the school file server.',
                            );
                        }


                        $uploadedRemoteFiles[] =
                            $fileName;


                        $connection
                            ->table('inbox_reply_files')
                            ->insert([
                                'id' =>
                                    (string) Str::uuid(),

                                'inbox_reply_id' =>
                                    $replyId,

                                'filename' =>
                                    $fileName,

                                'order_no' =>
                                    $timestamp,

                                'file_desc' =>
                                    $originalFileName,
                            ]);
                    }
                }


                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->update([
                        'last_update' =>
                            $timestamp,
                    ]);


                $connection
                    ->commit();


                if ($ftpConnection) {
                    @ftp_close(
                        $ftpConnection,
                    );

                    $ftpConnection =
                        null;
                }


                return response()->json([
                    'success' => true,

                    'message' =>
                        'Reply sent successfully.',

                    'data' => [
                        'reply_id' =>
                            $replyId,

                        'inbox_id' =>
                            $inboxId,

                        'reply_msg' =>
                            $replyMessage,

                        'reply_date' =>
                            $timestamp,

                        'date_read' =>
                            null,

                        'sender_id' =>
                            $senderId,

                        'recipient_id' =>
                            $recipientId,

                        'attachment_count' =>
                            count(
                                $uploadedRemoteFiles,
                            ),
                    ],
                ], 201);
            } catch (Throwable $throwable) {
                if (
                    $connection
                        ->transactionLevel() > 0
                ) {
                    $connection
                        ->rollBack();
                }


                if ($ftpConnection) {
                    foreach (
                        $uploadedRemoteFiles as
                        $remoteFile
                    ) {
                        @ftp_delete(
                            $ftpConnection,
                            $remoteFile,
                        );
                    }


                    @ftp_close(
                        $ftpConnection,
                    );

                    $ftpConnection =
                        null;
                }


                throw $throwable;
            }
        } catch (Throwable $throwable) {
            report($throwable);


            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to send reply.',

                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }

    /**
     * Delete the complete conversation history for both participants.
     *
     * This intentionally removes the conversation rows instead of creating a
     * second per-user hidden-history system. Both participants therefore see
     * the conversation disappear after their next sidebar refresh.
     */
    public function deleteConversationHistory(
        Request $request,
        string $inboxId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);

        $connection =
            $this->schoolConnection(
                $request,
            );

        try {
            $inbox =
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'recipient_id',
                    ])
                    ->first();

            if (!$inbox) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Conversation not found.',
                ], 404);
            }

            $userId =
                (string) $validated['user_id'];

            $isParticipant =
                (string) $inbox->sender_id ===
                    $userId
                || (string) $inbox->recipient_id ===
                    $userId;

            if (!$isParticipant) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'You are not a participant in this conversation.',
                ], 403);
            }

            if (
                $this->hasModerationReport(
                    $connection,
                    $inboxId,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This conversation cannot be deleted while it has a moderation report under review.',
                ], 409);
            }

            $inboxFileNames =
                $connection
                    ->table('inbox_files')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->pluck(
                        'filename',
                    )
                    ->filter()
                    ->values()
                    ->all();

            $replyIds =
                $connection
                    ->table('inbox_reply')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->pluck(
                        'id',
                    )
                    ->filter()
                    ->values();

            $replyFileNames =
                $replyIds->isEmpty()
                    ? []
                    : $connection
                        ->table('inbox_reply_files')
                        ->whereIn(
                            'inbox_reply_id',
                            $replyIds,
                        )
                        ->pluck(
                            'filename',
                        )
                        ->filter()
                        ->values()
                        ->all();

            $connection
                ->beginTransaction();

            try {
                if (!$replyIds->isEmpty()) {
                    $connection
                        ->table('inbox_reply_files')
                        ->whereIn(
                            'inbox_reply_id',
                            $replyIds,
                        )
                        ->delete();
                }

                $connection
                    ->table('inbox_files')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->delete();

                $connection
                    ->table('inbox_reply')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->delete();

                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->delete();

                $connection
                    ->commit();
            } catch (Throwable $throwable) {
                if (
                    $connection
                        ->transactionLevel() > 0
                ) {
                    $connection
                        ->rollBack();
                }

                throw $throwable;
            }

            $this->deleteStoredMessageFiles(
                $request,
                array_merge(
                    $inboxFileNames,
                    $replyFileNames,
                ),
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Conversation history deleted successfully.',
                'data' => [
                    'inbox_id' =>
                        $inboxId,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'success' => false,
                'message' =>
                    'Unable to delete the conversation history.',
                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    /**
     * Replace the original message with a shared deleted-message placeholder.
     *
     * Only the sender may delete their own message. The inbox row stays in
     * place so both participants see "This message was deleted".
     */
    public function deleteOriginalMessage(
        Request $request,
        string $inboxId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);

        $connection =
            $this->schoolConnection(
                $request,
            );

        try {
            $inbox =
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'content_inbox',
                    ])
                    ->first();

            if (!$inbox) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Message not found.',
                ], 404);
            }

            if (
                (string) $inbox->sender_id !==
                (string) $validated['user_id']
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'You can only delete messages that you sent.',
                ], 403);
            }

            if (
                $this->hasModerationReport(
                    $connection,
                    $inboxId,
                    null,
                    true,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This message cannot be deleted while it has a moderation report under review.',
                ], 409);
            }

            if (
                $this->isDeletedMessage(
                    $inbox->content_inbox
                    ?? null,
                )
            ) {
                return response()->json([
                    'success' => true,
                    'message' =>
                        'Message is already deleted.',
                    'data' => [
                        'inbox_id' =>
                            $inboxId,
                        'is_deleted' =>
                            true,
                    ],
                ]);
            }

            $fileNames =
                $connection
                    ->table('inbox_files')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->pluck(
                        'filename',
                    )
                    ->filter()
                    ->values()
                    ->all();

            $connection
                ->beginTransaction();

            try {
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $inboxId,
                    )
                    ->where(
                        'sender_id',
                        $validated['user_id'],
                    )
                    ->update([
                        'content_inbox' =>
                            self::DELETED_MESSAGE_MARKER,
                    ]);

                $connection
                    ->table('inbox_files')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->delete();

                $connection
                    ->commit();
            } catch (Throwable $throwable) {
                if (
                    $connection
                        ->transactionLevel() > 0
                ) {
                    $connection
                        ->rollBack();
                }

                throw $throwable;
            }

            $this->deleteStoredMessageFiles(
                $request,
                $fileNames,
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Message deleted successfully.',
                'data' => [
                    'inbox_id' =>
                        $inboxId,
                    'is_deleted' =>
                        true,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'success' => false,
                'message' =>
                    'Unable to delete the message.',
                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    /**
     * Replace one reply with a shared deleted-message placeholder.
     */
    public function deleteMessageReply(
        Request $request,
        string $inboxId,
        string $replyId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);

        $connection =
            $this->schoolConnection(
                $request,
            );

        try {
            $reply =
                $connection
                    ->table('inbox_reply')
                    ->where(
                        'id',
                        $replyId,
                    )
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'reply_msg',
                    ])
                    ->first();

            if (!$reply) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Reply not found.',
                ], 404);
            }

            if (
                (string) $reply->sender_id !==
                (string) $validated['user_id']
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'You can only delete messages that you sent.',
                ], 403);
            }

            if (
                $this->hasModerationReport(
                    $connection,
                    $inboxId,
                    $replyId,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This message cannot be deleted while it has a moderation report under review.',
                ], 409);
            }

            if (
                $this->isDeletedMessage(
                    $reply->reply_msg
                    ?? null,
                )
            ) {
                return response()->json([
                    'success' => true,
                    'message' =>
                        'Message is already deleted.',
                    'data' => [
                        'reply_id' =>
                            $replyId,
                        'inbox_id' =>
                            $inboxId,
                        'is_deleted' =>
                            true,
                    ],
                ]);
            }

            $fileNames =
                $connection
                    ->table('inbox_reply_files')
                    ->where(
                        'inbox_reply_id',
                        $replyId,
                    )
                    ->pluck(
                        'filename',
                    )
                    ->filter()
                    ->values()
                    ->all();

            $connection
                ->beginTransaction();

            try {
                $connection
                    ->table('inbox_reply')
                    ->where(
                        'id',
                        $replyId,
                    )
                    ->where(
                        'inbox_id',
                        $inboxId,
                    )
                    ->where(
                        'sender_id',
                        $validated['user_id'],
                    )
                    ->update([
                        'reply_msg' =>
                            self::DELETED_MESSAGE_MARKER,
                    ]);

                $connection
                    ->table('inbox_reply_files')
                    ->where(
                        'inbox_reply_id',
                        $replyId,
                    )
                    ->delete();

                $connection
                    ->commit();
            } catch (Throwable $throwable) {
                if (
                    $connection
                        ->transactionLevel() > 0
                ) {
                    $connection
                        ->rollBack();
                }

                throw $throwable;
            }

            $this->deleteStoredMessageFiles(
                $request,
                $fileNames,
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Message deleted successfully.',
                'data' => [
                    'reply_id' =>
                        $replyId,
                    'inbox_id' =>
                        $inboxId,
                    'is_deleted' =>
                        true,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'success' => false,
                'message' =>
                    'Unable to delete the message.',
                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    /**
     * Store a user-generated content report for support review.
     */
    public function reportMessage(
        Request $request,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'inbox_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'inbox_reply_id' => [
                    'nullable',
                    'string',
                    'max:36',
                ],

                'reported_user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'reason' => [
                    'required',
                    'string',
                    'in:harassment,threats,hate,sexual,spam,other',
                ],

                'details' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);


        $connection =
            $this->schoolConnection($request);


        try {
            $reporterId =
                $validated['user_id'];


            $inbox =
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $validated['inbox_id'],
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'recipient_id',
                    ])
                    ->first();


            if (!$inbox) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Conversation not found.',
                ], 404);
            }


            $isParticipant =
                (string) $inbox->sender_id ===
                    (string) $reporterId
                || (string) $inbox->recipient_id ===
                    (string) $reporterId;


            if (!$isParticipant) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'You are not a participant in this conversation.',
                ], 403);
            }


            $reportedMessageSenderId =
                (string) $inbox->sender_id;


            $replyId =
                trim(
                    (string) (
                        $validated['inbox_reply_id']
                        ?? ''
                    ),
                );


            if ($replyId !== '') {
                $reply =
                    $connection
                        ->table('inbox_reply')
                        ->where(
                            'id',
                            $replyId,
                        )
                        ->where(
                            'inbox_id',
                            $validated['inbox_id'],
                        )
                        ->select([
                            'sender_id',
                        ])
                        ->first();


                if (!$reply) {
                    return response()->json([
                        'success' => false,

                        'message' =>
                            'The selected reply could not be found.',
                    ], 404);
                }


                $reportedMessageSenderId =
                    (string) $reply->sender_id;
            }


            if (
                $reportedMessageSenderId === ''
                || $reportedMessageSenderId !==
                    (string) $validated['reported_user_id']
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'The reported user does not match the selected message.',
                ], 422);
            }


            if (
                $reportedMessageSenderId ===
                (string) $reporterId
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'You cannot report your own message.',
                ], 422);
            }


            $reportId =
                (string) Str::uuid();


            $connection
                ->table('message_reports')
                ->insert([
                    'id' =>
                        $reportId,

                    'inbox_id' =>
                        $validated['inbox_id'],

                    'inbox_reply_id' =>
                        $replyId !== ''
                            ? $replyId
                            : null,

                    'reported_by' =>
                        $reporterId,

                    'reported_user_id' =>
                        $reportedMessageSenderId,

                    'reason' =>
                        $validated['reason'],

                    'details' =>
                        isset(
                            $validated['details'],
                        )
                            ? trim(
                                (string) $validated['details'],
                            )
                            : null,

                    'status' =>
                        'pending',

                    'created_at' =>
                        now()->format(
                            'Y-m-d H:i:s',
                        ),
                ]);


            return response()->json([
                'success' => true,

                'message' =>
                    'Report submitted successfully.',

                'data' => [
                    'report_id' =>
                        $reportId,

                    'status' =>
                        'pending',
                ],
            ], 201);
        } catch (Throwable $throwable) {
            report($throwable);


            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to submit the report.',

                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    /**
     * Block another participant for the current user only.
     *
     * No person/login account is disabled. The block is private to the user* who created it. A moderation report is also created so support has a
     * record of the block action for review.
     */
    public function blockUser(
        Request $request,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'blocked_user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'inbox_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);


        $blockerId =
            $validated['user_id'];


        $blockedUserId =
            $validated['blocked_user_id'];


        if (
            (string) $blockerId ===
            (string) $blockedUserId
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'You cannot block your own account.',
            ], 422);
        }


        $connection =
            $this->schoolConnection($request);


        try {
            $inbox =
                $connection
                    ->table('inbox')
                    ->where(
                        'id',
                        $validated['inbox_id'],
                    )
                    ->select([
                        'id',
                        'sender_id',
                        'recipient_id',
                    ])
                    ->first();


            if (!$inbox) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Conversation not found.',
                ], 404);
            }


            $isSender =
                (string) $inbox->sender_id ===
                (string) $blockerId;


            $isRecipient =
                (string) $inbox->recipient_id ===
                (string) $blockerId;


            if (
                !$isSender
                && !$isRecipient
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'You are not a participant in this conversation.',
                ], 403);
            }


            $otherUserId =
                $isSender
                    ? (string) $inbox->recipient_id
                    : (string) $inbox->sender_id;


            if (
                $otherUserId === ''
                || $otherUserId !==
                    (string) $blockedUserId
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'The selected user is not the other participant in this conversation.',
                ], 422);
            }


            $connection
                ->beginTransaction();


            try {
                $alreadyBlocked =
                    $connection
                        ->table('message_blocks')
                        ->where(
                            'blocker_id',
                            $blockerId,
                        )
                        ->where(
                            'blocked_user_id',
                            $blockedUserId,
                        )
                        ->exists();


                if (!$alreadyBlocked) {
                    $connection
                        ->table('message_blocks')
                        ->insert([
                            'id' =>
                                (string) Str::uuid(),

                            'blocker_id' =>
                                $blockerId,

                            'blocked_user_id' =>
                                $blockedUserId,

                            'created_at' =>
                                now()->format(
                                    'Y-m-d H:i:s',
                                ),
                        ]);


                    /*
                     * Apple requires a block action to notify the developer of
                     * inappropriate behavior. Store a pending moderation record
                     * without suspending or changing the blocked account.
                     */
                    $connection
                        ->table('message_reports')
                        ->insert([
                            'id' =>
                                (string) Str::uuid(),

                            'inbox_id' =>
                                $validated['inbox_id'],

                            'inbox_reply_id' =>
                                null,

                            'reported_by' =>
                                $blockerId,

                            'reported_user_id' =>
                                $blockedUserId,

                            'reason' =>
                                'blocked_user',

                            'details' =>
                                'User blocked this account from a conversation. Review the conversation for inappropriate behavior.',

                            'status' =>
                                'pending',

                            'created_at' =>
                                now()->format(
                                    'Y-m-d H:i:s',
                                ),
                        ]);
                }


                $connection
                    ->commit();
            } catch (Throwable $throwable) {
                if (
                    $connection
                        ->transactionLevel() > 0
                ) {
                    $connection
                        ->rollBack();
                }


                throw $throwable;
            }


            return response()->json([
                'success' => true,

                'message' =>
                    $alreadyBlocked
                        ? 'User is already blocked.'
                        : 'User blocked successfully.',

                'data' => [
                    'blocked_user_id' =>
                        $blockedUserId,

                    'is_blocked' =>
                        true,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);


            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to block the user.',

                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }


    /**
     * Remove only the current user's block against another account.
     */
    public function unblockUser(
        Request $request,
    ): JsonResponse {
        $validated =
            $request->validate([
                'user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],

                'blocked_user_id' => [
                    'required',
                    'string',
                    'max:36',
                ],
            ]);


        try {
            $this->schoolConnection($request)
                ->table('message_blocks')
                ->where(
                    'blocker_id',
                    $validated['user_id'],
                )
                ->where(
                    'blocked_user_id',
                    $validated['blocked_user_id'],
                )
                ->delete();


            return response()->json([
                'success' => true,

                'message' =>
                    'User unblocked successfully.',

                'data' => [
                    'blocked_user_id' =>
                        $validated['blocked_user_id'],

                    'is_blocked' =>
                        false,
                ],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);


            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to unblock the user.',

                'error' =>
                    config('app.debug')
                        ? $throwable->getMessage()
                        : null,
            ], 500);
        }
    }



    //POST

    public function fetchAdministrators(
    Request $request,
): JsonResponse {
    try {
        $administrators =
            $this->schoolConnection($request)
                ->table('login')
                ->where(
                    'active',
                    'Y',
                )
                ->select([
                    'id',
                    'login_name',
                    'fname',
                    'mname',
                    'lname',
                    'email',
                ])
                ->orderBy(
                    'lname',
                )
                ->orderBy(
                    'fname',
                )
                ->get()
                ->map(function ($administrator) {
                    $fullName =
                        trim(
                            implode(
                                ' ',
                                array_filter([
                                    $administrator->fname ?? null,
                                    $administrator->mname ?? null,
                                    $administrator->lname ?? null,
                                ]),
                            ),
                        );

                    return [
                        'id' =>
                            $administrator->id,

                        'recipient_type' =>
                            'Admin',

                        'full_name' =>
                            $fullName !== ''
                                ? $fullName
                                : (
                                    trim(
                                        (string) (
                                            $administrator->login_name
                                            ?? ''
                                        ),
                                    ) !== ''
                                        ? $administrator->login_name
                                        : (
                                            'Administrator '
                                            . substr(
                                                (string) $administrator->id,
                                                0,
                                                8,
                                            )
                                        )
                                ),

                        'login_name' =>
                            $administrator->login_name,

                        'email' =>
                            $administrator->email,
                    ];
                })
                ->values();

        return response()->json([
            'success' => true,

            'message' =>
                'Administrators fetched successfully.',

            'data' =>
                $administrators,
        ]);
    } catch (Throwable $throwable) {
        report($throwable);

        return response()->json([
            'success' => false,

            'message' =>
                'Unable to fetch administrators.',

            'error' =>
                config('app.debug')
                    ? $throwable->getMessage()
                    : null,
        ], 500);
    }
}


public function fetchStudents(
    Request $request,
): JsonResponse {
    try {
        $students =
            $this->schoolConnection($request)
                ->table('person')
                ->select([
                    'id',
                    'code_person',
                    'fname',
                    'mname',
                    'lname',
                    'gender',
                    'photo_file',
                    'email',
                ])
                ->orderBy(
                    'lname',
                )
                ->orderBy(
                    'fname',
                )
                ->get()
                ->map(
                    function ($student) use (
                        $request,
                    ) {
                        $fullName =
                            trim(
                                implode(
                                    ' ',
                                    array_filter([
                                        $student->fname ?? null,
                                        $student->mname ?? null,
                                        $student->lname ?? null,
                                    ]),
                                ),
                            );

                        return [
                            'id' =>
                                $student->id,

                            'recipient_type' =>
                                'Cadet',

                            'full_name' =>
                                $fullName !== ''
                                    ? $fullName
                                    : (
                                        'Cadet '
                                        . substr(
                                            (string) $student->id,
                                            0,
                                            8,
                                        )
                                    ),

                            'code_person' =>
                                $student->code_person,

                            'gender' =>
                                $student->gender,

                            'photo_url' =>
                                $this
                                    ->buildStudentPhotoUrl(
                                        $request,
                                        $student->photo_file,
                                    ),

                            'email' =>
                                $student->email,
                        ];
                    },
                )
                ->values();

        return response()->json([
            'success' => true,
            'message' =>
                'Students fetched successfully.',
            'data' =>
                $students,
        ]);
    } catch (Throwable $throwable) {
        report($throwable);

        return response()->json([
            'success' => false,
            'message' =>
                'Unable to fetch students.',
            'error' =>
                config('app.debug')
                    ? $throwable->getMessage()
                    : null,
        ], 500);
    }
}


public function storeMessage(
    Request $request,
): JsonResponse {
    $validated =
        $request->validate([
            'user_id' => [
                'required',
                'string',
                'max:36',
            ],

            'recipient_id' => [
                'required',
                'string',
                'max:36',
            ],

            'recipient_type' => [
                'required',
                'string',
                'in:login,person,admin,student,Admin,Cadet,cadet',
            ],

            'subject' => [
                'required',
                'string',
                'max:100',
            ],

            'content' => [
                'nullable',
                'string',
            ],

            'files' => [
                'nullable',
                'array',
            ],

            'files.*' => [
                'file',
                'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
                'max:20480',
            ],
        ]);


    $messageContent =
        trim(
            (string) (
                $validated['content']
                ?? ''
            ),
        );


    $moderationResult =
        $this
            ->messageContentFilter()
            ->inspect(
                $validated['subject'],
                $messageContent,
            );


    if (!$moderationResult['allowed']) {
        return
            $this
                ->contentNotAllowedResponse();
    }


    $connection =
        $this->schoolConnection($request);


    $recipientTable =
        in_array(
            strtolower(
                $validated['recipient_type'],
            ),
            [
                'login',
                'admin',
            ],
            true,
        )
            ? 'login'
            : 'person';


    $recipientType =
        $recipientTable === 'login'
            ? 'Admin'
            : 'Cadet';


    try {
        /*
        |--------------------------------------------------------------------------
        | Validate Sender
        |--------------------------------------------------------------------------
        */

        $senderExistsInPerson =
            $connection
                ->table('person')
                ->where(
                    'id',
                    $validated['user_id'],
                )
                ->exists();


        $senderExistsInLogin =
            $connection
                ->table('login')
                ->where(
                    'id',
                    $validated['user_id'],
                )
                ->exists();


        if (
            !$senderExistsInPerson &&
            !$senderExistsInLogin
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Sender not found.',
            ], 404);
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Recipient
        |--------------------------------------------------------------------------
        */

        $recipientExists =
            $connection
                ->table(
                    $recipientTable,
                )
                ->where(
                    'id',
                    $validated['recipient_id'],
                )
                ->exists();


        if (!$recipientExists) {
            return response()->json([
                'success' => false,

                'message' =>
                    $recipientTable === 'login'
                        ? 'Administrator not found.'
                        : 'Cadet not found.',
            ], 404);
        }


        /*
         * Enforce a stored block in either direction when the selected school
         * has moderation tables. Missing moderation tables never interrupt the
         * existing messaging flow.
         */
        try {
            $hasMessageBlock =
                $connection
                    ->table('message_blocks')
                    ->where(
                        function ($query) use (
                            $validated,
                        ) {
                            $query
                                ->where(
                                    'blocker_id',
                                    $validated['user_id'],
                                )
                                ->where(
                                    'blocked_user_id',
                                    $validated['recipient_id'],
                                );
                        },
                    )
                    ->orWhere(
                        function ($query) use (
                            $validated,
                        ) {
                            $query
                                ->where(
                                    'blocker_id',
                                    $validated['recipient_id'],
                                )
                                ->where(
                                    'blocked_user_id',
                                    $validated['user_id'],
                                );
                        },
                    )
                    ->exists();


            if ($hasMessageBlock) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Messaging is unavailable because one of these users has blocked the other.',
                ], 403);
            }
        } catch (Throwable $moderationThrowable) {
            report(
                $moderationThrowable,
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Prepare Uploaded Files
        |--------------------------------------------------------------------------
        |
        | The API server and the selected school's web server may be different
        | machines. Therefore message attachments must use the currently selected
        | school's FTP configuration instead of PHP filesystem paths such as:
        |
        | /www/exact-cme-iris.ph/uploads
        |
        | No FTP connection is created when the message has no attachments.
        |
        */

        $uploadedFiles =
            $request->file(
                'files',
                [],
            );


        if (!is_array($uploadedFiles)) {
            $uploadedFiles =
                $uploadedFiles
                    ? [$uploadedFiles]
                    : [];
        }


        $uploadedFiles =
            array_values(
                array_filter(
                    $uploadedFiles,
                    static function ($uploadedFile) {
                        return
                            $uploadedFile
                            && $uploadedFile->isValid();
                    },
                ),
            );


        $hasAttachments =
            count($uploadedFiles) > 0;


        if (
            $messageContent === ''
            && !$hasAttachments
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'A message or attachment is required.',
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | Current School FTP Configuration
        |--------------------------------------------------------------------------
        */

        $messageStorage =
            $this->messageStorage(
                $request,
            );


        $ftpHost =
            $messageStorage[
                'ftp_host'
            ];


        $ftpUsername =
            $messageStorage[
                'ftp_username'
            ];


        $ftpPassword =
            $messageStorage[
                'ftp_password'
            ];


        $ftpPort =
            $messageStorage[
                'ftp_port'
            ];


        $ftpRoot =
            $messageStorage[
                'ftp_root'
            ];


        if ($hasAttachments) {
            if (
                $ftpHost === ''
                || $ftpUsername === ''
                || $ftpPassword === ''
                || $ftpPort <= 0
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'School message attachment storage is not configured.',
                ], 500);
            }


            if (!function_exists('ftp_connect')) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'FTP support is not available on the API server.',
                ], 500);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Create Message
        |--------------------------------------------------------------------------
        */

        $inboxId =
            (string) Str::uuid();


        $timestamp =
            now()->format(
                'Y-m-d H:i:s',
            );


        $uploadedRemoteFiles = [];


        $ftpConnection =
            null;


        $connection
            ->beginTransaction();


        try {
            /*
            |--------------------------------------------------------------------------
            | Inbox Record
            |--------------------------------------------------------------------------
            */

            $connection
                ->table('inbox')
                ->insert([
                    'id' =>
                        $inboxId,

                    'subj_inbox' =>
                        $validated['subject'],

                    'date_inbox' =>
                        $timestamp,

                    'date_read' =>
                        null,

                    'recipient_type' =>
                        $recipientType,

                    'recipient_id' =>
                        $validated['recipient_id'],

                    'sender_id' =>
                        $validated['user_id'],

                    'content_inbox' =>
                        $messageContent,

                    /*
                    |--------------------------------------------------------------------------
                    | Owner / Logged-in User
                    |--------------------------------------------------------------------------
                    */

                    'login_id' =>
                        $validated['user_id'],

                    'last_update' =>
                        $timestamp,

                    'draft' =>
                        'N',
                ]);


            /*
            |--------------------------------------------------------------------------
            | Attachments
            |--------------------------------------------------------------------------
            */

            if ($hasAttachments) {
                $ftpConnection =
                    @ftp_connect(
                        $ftpHost,
                        $ftpPort,
                        30,
                    );


                if (!$ftpConnection) {
                    throw new \RuntimeException(
                        'Unable to connect to the school file server.',
                    );
                }


                $loggedIn =
                    @ftp_login(
                        $ftpConnection,
                        $ftpUsername,
                        $ftpPassword,
                    );


                if (!$loggedIn) {
                    throw new \RuntimeException(
                        'Unable to authenticate with the school file server.',
                    );
                }


                @ftp_pasv(
                    $ftpConnection,
                    true,
                );if (
                    !@ftp_chdir(
                        $ftpConnection,
                        $ftpRoot,
                    )
                ) {
                    throw new \RuntimeException(
                        'Message upload directory does not exist on the school file server: '
                        . $ftpRoot,
                    );
                }


                foreach (
                    $uploadedFiles as
                    $uploadedFile
                ) {
                    $extension =
                        strtolower(
                            $uploadedFile
                                ->getClientOriginalExtension()
                            ?: $uploadedFile
                                ->extension()
                            ?: 'bin',
                        );


                    $fileName =
                        'message_'
                        . $inboxId
                        . '_'
                        . Str::lower(
                            Str::random(8),
                        )
                        . '.'
                        . $extension;


                    $originalFileName =
                        $uploadedFile
                            ->getClientOriginalName();


                    $localPath =
                        $uploadedFile
                            ->getRealPath();


                    if (
                        !$localPath
                        || !is_file($localPath)
                    ) {
                        throw new \RuntimeException(
                            'The uploaded attachment could not be read.',
                        );
                    }


                    $stream =
                        @fopen(
                            $localPath,
                            'rb',
                        );


                    if (!$stream) {
                        throw new \RuntimeException(
                            'Unable to read the uploaded attachment.',
                        );
                    }


                    try {
                        $uploaded =
                            @ftp_fput(
                                $ftpConnection,
                                $fileName,
                                $stream,
                                FTP_BINARY,
                            );
                    } finally {
                        fclose(
                            $stream,
                        );
                    }


                    if (!$uploaded) {
                        throw new \RuntimeException(
                            'Unable to upload attachment to the school file server.',
                        );
                    }


                    $uploadedRemoteFiles[] =
                        $fileName;


                    $connection
                        ->table('inbox_files')
                        ->insert([
                            'id' =>
                                (string) Str::uuid(),

                            'inbox_id' =>
                                $inboxId,

                            'filename' =>
                                $fileName,

                            'order_no' =>
                                $timestamp,

                            'file_desc' =>
                                $originalFileName,
                        ]);


                }
            }


            $connection
                ->commit();


            if ($ftpConnection) {
                @ftp_close(
                    $ftpConnection,
                );

                $ftpConnection =
                    null;
            }


            return response()->json([
                'success' => true,

                'message' =>
                    'Message sent successfully.',

                'data' => [
                    'id' =>
                        $inboxId,

                    'sender_id' =>
                        $validated['user_id'],

                    'recipient_id' =>
                        $validated['recipient_id'],

                    'recipient_type' =>
                        $recipientType,

                    'subject' =>
                        $validated['subject'],

                    'content' =>
                        $messageContent,

                    'date_inbox' =>
                        $timestamp,

                    'date_read' =>
                        null,

                    'attachment_count' =>
                        count(
                            $uploadedRemoteFiles,
                        ),
                ],
            ], 201);
        } catch (Throwable $throwable) {
            if (
                $connection
                    ->transactionLevel() > 0
            ) {
                $connection
                    ->rollBack();
            }


            /*
            |--------------------------------------------------------------------------
            | Remove FTP Files When Database Transaction Fails
            |--------------------------------------------------------------------------
            */

            if ($ftpConnection) {
                foreach (
                    $uploadedRemoteFiles as
                    $remoteFile
                ) {
                    @ftp_delete(
                        $ftpConnection,
                        $remoteFile,
                    );
                }


                @ftp_close(
                    $ftpConnection,
                );

                $ftpConnection =
                    null;
            }


            throw $throwable;
        }
    } catch (Throwable $throwable) {
        report($throwable);


        return response()->json([
            'success' => false,

            'message' =>
                'Unable to send the message.',

            'error' =>
                config('app.debug')
                    ? $throwable->getMessage()
                    : null,
        ], 500);
    }
}

    /**
     * Keep reported content available to the existing admin moderation flow.
     *
     * Older school databases may not have the moderation table yet, so absence
     * of that optional table must never break normal messaging.
     */
    private function hasModerationReport(
        ConnectionInterface $connection,
        string $inboxId,
        ?string $replyId = null,
        bool $originalOnly = false,
    ): bool {
        try {
            if (
                !$connection
                    ->getSchemaBuilder()
                    ->hasTable(
                        'message_reports',
                    )
            ) {
                return false;
            }

            $query =
                $connection
                    ->table('message_reports')
                    ->where(
                        'inbox_id',
                        $inboxId,
                    );

            if ($replyId !== null) {
                $query->where(
                    'inbox_reply_id',
                    $replyId,
                );
            } elseif ($originalOnly) {
                $query->whereNull(
                    'inbox_reply_id',
                );
            }

            return $query->exists();
        } catch (Throwable $throwable) {
            report($throwable);

            /*
             * Fail closed here so a moderation lookup problem never allows
             * reported content to be erased accidentally.
             */
            return true;
        }
    }


    private function isDeletedMessage(
        ?string $value,
    ): bool {
        return hash_equals(
            self::DELETED_MESSAGE_MARKER,
            (string) $value,
        );
    }


    /**
     * Remove attachment bytes after their database rows have been deleted.
     *
     * File cleanup is best-effort so a temporary FTP problem never rolls back a
     * successful message delete. Any storage failure is still reported to the
     * Laravel log for support review.
     *
     * @param array<int, mixed> $fileNames
     */
    private function deleteStoredMessageFiles(
        Request $request,
        array $fileNames,
    ): void {
        $fileNames =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static function ($fileName) {
                                return basename(
                                    str_replace(
                                        '\\',
                                        '/',
                                        trim(
                                            (string) $fileName,
                                        ),
                                    ),
                                );
                            },
                            $fileNames,
                        ),
                    ),
                ),
            );

        if ($fileNames === []) {
            return;
        }

        try {
            $storage =
                $this->messageStorage(
                    $request,
                );

            if (
                !function_exists('ftp_connect')
                || $storage['ftp_host'] === ''
                || $storage['ftp_username'] === ''
                || $storage['ftp_password'] === ''
                || $storage['ftp_port'] <= 0
            ) {
                return;
            }

            $ftpConnection =
                @ftp_connect(
                    $storage['ftp_host'],
                    $storage['ftp_port'],
                    30,
                );

            if (!$ftpConnection) {
                return;
            }

            try {
                if (
                    !@ftp_login(
                        $ftpConnection,
                        $storage['ftp_username'],
                        $storage['ftp_password'],
                    )
                ) {
                    return;
                }

                @ftp_pasv(
                    $ftpConnection,
                    true,
                );

                if (
                    !@ftp_chdir(
                        $ftpConnection,
                        $storage['ftp_root'],
                    )
                ) {
                    return;
                }

                foreach ($fileNames as $fileName) {
                    @ftp_delete(
                        $ftpConnection,
                        $fileName,
                    );
                }
            } finally {
                @ftp_close(
                    $ftpConnection,
                );
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }


    /**
     * Resolve the database selected during login.
     */
    private function selectedSchoolCode(
        Request $request,
    ): string {
        return strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get(
                        'school_code',
                        '',
                    ),
            ),
        );
    }

    private function buildStudentPhotoUrl(
        Request $request,
        ?string $photoFile,
    ): ?string {
        $schoolCode =
            $this->selectedSchoolCode(
                $request,
            );

        if ($schoolCode === '') {
            return null;
        }

        $photosUrl =
            rtrim(
                trim(
                    (string) config(
                        "schools.schools.{$schoolCode}.files.photos_url",
                        '',
                    ),
                ),
                '/',
            );

        if ($photosUrl === '') {
            return null;
        }

        $filename =
            basename(
                str_replace(
                    '\\',
                    '/',
                    trim(
                        (string) $photoFile,
                    ),
                ),
            );

        /*
         * Keep the same profile-photo fallback standard used by
         * Students:
         * actual photo -> gender avatar -> initials.
         *
         * profile.jpg is the legacy generic silhouette, not an
         * actual student photo.
         */
        if (
            $filename === ''
            || strtolower(
                $filename,
            ) === 'profile.jpg'
        ) {
            return null;
        }

        return $photosUrl
            . '/'
            . rawurlencode(
                $filename,
            );
    }

    /**
     * Resolve message attachment storage for the school selected at login.
     *
     * Message attachments use the selected school's general FTP uploads folder.
     * Their public URL uses message.upload_url when configured and falls back to
     * the existing journal upload URL for legacy IRIS-SAM compatibility.
     *
     * @return array{
     *     ftp_host: string,
     *     ftp_username: string,
     *     ftp_password: string,
     *     ftp_port: int,
     *     ftp_root: string,
     *     upload_url: string
     * }
     */
    private function messageStorage(
        Request $request,
    ): array {
        $code =
            $this->selectedSchoolCode(
                $request,
            );

        /*
         * Primary IRIS-SAM WEB configuration.
         */
        $school =
            config(
                "schools.schools.{$code}",
                [],
            );

        $storage =
            is_array($school)
            && is_array(
                $school['storage']
                ?? null,
            )
                ? $school['storage']
                : [];

        /*
         * Compatibility with the existing IRIS-SAM API configuration.
         */
        if ($storage === []) {
            $legacyStorage =
                config(
                    "database.connections.admapro.schools.{$code}.storage",
                    [],
                );

            $storage =
                is_array(
                    $legacyStorage,
                )
                    ? $legacyStorage
                    : [];
        }

        $ftp =
            is_array(
                $storage['ftp']
                ?? null,
            )
                ? $storage['ftp']
                : [];

        $message =
            is_array(
                $storage['message']
                ?? null,
            )
                ? $storage['message']
                : [];

        $journal =
            is_array(
                $storage['journal']
                ?? null,
            )
                ? $storage['journal']
                : [];

        /*
         * Dynamic admapro.* values remain valid when the older middleware has
         * already populated them. They are fallback values only.
         */
        $ftpHost =
            trim(
                (string) (
                    $ftp['host']
                    ??
                    config(
                        'admapro.ftp.host',
                        '',
                    )
                ),
            );

        $ftpUsername =
            trim(
                (string) (
                    $ftp['username']
                    ??
                    config(
                        'admapro.ftp.username',
                        '',
                    )
                ),
            );

        $ftpPassword =
            (string) (
                $ftp['password']
                ??
                config(
                    'admapro.ftp.password',
                    '',
                )
            );

        $ftpPort =
            (int) (
                $ftp['port']
                ??
                config(
                    'admapro.ftp.port',
                    21,
                )
            );

        $ftpRoot =
            trim(
                (string) (
                    $ftp['root']
                    ??
                    config(
                        'admapro.ftp.root',
                        '/uploads',
                    )
                ),
            );

        if ($ftpRoot === '') {
            $ftpRoot =
                '/uploads';
        }

        $ftpRoot =
            '/' . trim(
                $ftpRoot,
                '/',
            );

        $uploadUrl =
            trim(
                (string) (
                    $message['upload_url']
                    ??
                    config(
                        'admapro.message.upload_url',
                        '',
                    )
                    ??
                    ''
                ),
            );

        if ($uploadUrl === '') {
            $uploadUrl =
                trim(
                    (string) (
                        $journal['upload_url']
                        ??
                        config(
                            'admapro.journal.upload_url',
                            '',
                        )
                        ??
                        ''
                    ),
                );
        }

        return [
            'ftp_host' =>
                $ftpHost,

            'ftp_username' =>
                $ftpUsername,

            'ftp_password' =>
                $ftpPassword,

            'ftp_port' =>
                $ftpPort,

            'ftp_root' =>
                $ftpRoot,

            'upload_url' =>
                rtrim(
                    $uploadUrl,
                    '/',
                ),
        ];
    }


    private function schoolConnection(
        Request $request,
    ): ConnectionInterface {
        $code = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get(
                        'school_code',
                        '',
                    ),
            ),
        );

        $schools = config(
            'schools.schools',
            [],
        );

        $school =
            is_array($schools)
                ? (
                    $schools[$code]
                    ?? null
                )
                : null;

        abort_unless(
            $code !== ''
            && is_array($school),
            403,
            'No valid school has been selected.',
        );

        abort_unless(
            hash_equals(
                strtoupper(
                    (string) (
                        $school['code']
                        ?? $code
                    ),
                ),
                $code,
            ),
            403,
            'The selected school code is invalid.',
        );

        $connection =
            $school['connection']
            ?? null;

        abort_unless(
            is_string($connection)
            && is_array(
                config(
                    "database.connections.{$connection}",
                ),
            ),
            500,
            'The school database connection is unavailable.',
        );

        return DB::connection(
            $connection,
        );
    }
}