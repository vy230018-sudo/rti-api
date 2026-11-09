<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminNewsController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\EcomDetailController;
use App\Http\Controllers\Api\EcomEnquiryController;
use App\Http\Controllers\Api\EcomSubscriptionController;
use App\Http\Controllers\Api\IdCardController;
use App\Http\Controllers\Api\ads_detailsController;
use App\Http\Controllers\Api\ads_viewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentInteractionController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CountController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\NewspaperController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuizTypeController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReferralCommissionRateController;
use App\Http\Controllers\Api\RoleSubscriptionController;
use App\Http\Controllers\Api\StateController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\SubscriptionPurchaseController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\QuestionAnsController;
use App\Http\Controllers\Api\UserSocialController;
use App\Http\Controllers\Api\AdminUserSocialController;
use App\Http\Controllers\Api\YoutubeController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\AdminWalletController;
use App\Http\Controllers\Api\PaymentHistoryController;
use App\Http\Controllers\Api\QuizSubscriptionController;
use App\Http\Controllers\Api\AdminQuizAttemptController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Middleware\AuthenticateWithJwt;
use App\Http\Middleware\EnsureAdminUser;
use App\Http\Middleware\ResolveOptionalJwt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

$registerRoutes = function (): void {
    Route::get('/test-mail', function () {
        Mail::raw('Test email from Laravel', function ($message) {
            $message->to('khushiroofze@gmail.com')->subject('Test Mail');
        });

        return response()->json(['message' => 'Mail sent!']);
    });

    // Restrict these routes with your admin middleware in production if appropriate.
    Route::prefix('youtube')->group(function () {
        Route::get('/auth', [YoutubeController::class, 'auth']);
        Route::get('/callback', [YoutubeController::class, 'callback']);
        Route::post('/live/create', [YoutubeController::class, 'create']);
        Route::post('/live/start', [YoutubeController::class, 'start']);
        Route::post('/live/end', [YoutubeController::class, 'end']);
        Route::get('/live/current', [YoutubeController::class, 'current']);
        Route::get('/live/list', [YoutubeController::class, 'liveList']);
    });

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password/request-otp', [AuthController::class, 'requestForgotPasswordOtp']);
    Route::post('/forgot-password/verify-otp', [AuthController::class, 'verifyForgotPasswordOtp']);
    Route::post('/forgot-password/reset-password', [AuthController::class, 'resetPasswordWithOtp']);
    Route::post('/register/request-otp', [AuthController::class, 'requestRegistrationOtp']);
    Route::post('/register/verify-otp', [AuthController::class, 'verifyRegistrationOtp']);
    Route::post('/account/reactivate', [AuthController::class, 'reactivateAccount']);
    Route::get('/id-cards/verify/{token}', [IdCardController::class, 'verify'])->where('token', '[A-Za-z0-9]+');

    Route::middleware(AuthenticateWithJwt::class)->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::match(['delete', 'post'], '/account', [AuthController::class, 'deleteAccount']);
        Route::get('/state', [StateController::class, 'show']);
        Route::post('/state', [StateController::class, 'store']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::post('/profile', [ProfileController::class, 'store']);
        Route::post('/profile/image', [ProfileController::class, 'update']);
        Route::put('/profile/image', [ProfileController::class, 'update']);
        Route::post('/referral/apply', [ReferralController::class, 'store']);
        Route::get('/referral', [ReferralController::class, 'index']);
        Route::get('/referrals', [ReferralController::class, 'index']);
        Route::post('/news', [NewsController::class, 'store']);
        Route::post('/news/{newsId}/report', [ReportController::class, 'reportNews'])->whereNumber('newsId');
        Route::post('/users/{userId}/report', [ReportController::class, 'reportUser'])->whereNumber('userId');
        Route::match(['post', 'put'], '/news/{id}', [NewsController::class, 'updateMyNews'])->whereNumber('id');
        Route::post('/likes', [LikeController::class, 'store']);
        Route::match(['delete', 'post'], '/likes/{newsId}', [LikeController::class, 'destroy']);
        Route::post('/counts', [CountController::class, 'store']);
        Route::post('/ads-views', [ads_viewController::class, 'store']);
        Route::get('/ads-views/{adsId}', [ads_viewController::class, 'show']);
        Route::post('/ads-details', [ads_detailsController::class, 'store']);
        Route::put('/ads-details/{id}', [ads_detailsController::class, 'update']);
        Route::match(['delete', 'post'], '/ads-details/{id}', [ads_detailsController::class, 'destroy']);
        Route::get('/ecom-enquiries', [EcomEnquiryController::class, 'index']);
        Route::get('/ecom-enquiries/received', [EcomEnquiryController::class, 'received']);   // NEW, {id} se pehle
        Route::post('/ecom-enquiries', [EcomEnquiryController::class, 'store']);
        Route::get('/ecom-enquiries/{id}', [EcomEnquiryController::class, 'show']);
        Route::put('/ecom-enquiries/{id}', [EcomEnquiryController::class, 'update']);
        Route::match(['delete', 'post'], '/ecom-enquiries/{id}', [EcomEnquiryController::class, 'destroy']);
        Route::post('/comments', [CommentController::class, 'store']);
        Route::match(['delete', 'post'], '/comments/{commentId}', [CommentController::class, 'destroy']);
           // Comment edit / like, reply add / edit / delete / like (naye APIs)
        // PUT/PATCH ke saath POST bhi allowed: kai shared hosts/WAF PUT & PATCH par 403 dete hain.
        Route::match(['put', 'patch', 'post'], '/comments/{commentId}', [CommentInteractionController::class, 'updateComment'])->whereNumber('commentId');
        Route::post('/comments/{commentId}/like', [CommentInteractionController::class, 'likeComment'])->whereNumber('commentId');
        Route::post('/comments/{commentId}/replies', [CommentInteractionController::class, 'storeReply'])->whereNumber('commentId');
        Route::match(['put', 'patch', 'post'], '/comment-replies/{replyId}', [CommentInteractionController::class, 'updateReply'])->whereNumber('replyId');
        Route::match(['delete', 'post'], '/comment-replies/{replyId}', [CommentInteractionController::class, 'destroyReply'])->whereNumber('replyId');
        Route::post('/comment-replies/{replyId}/like', [CommentInteractionController::class, 'likeReply'])->whereNumber('replyId');
        Route::get('/my-news', [NewsController::class, 'myNews']);
        Route::get('/my-news/{id}', [NewsController::class, 'showMyNews']);
        Route::put('/update-news/{id}', [NewsController::class, 'updateMyNews']);
        Route::match(['delete', 'post'], '/my-news/{id}', [NewsController::class, 'destroyMyNews']);
        Route::get('/quiz-types/practice', [QuizTypeController::class, 'practice']);
        Route::get('/quiz-types/training', [QuizTypeController::class, 'training']);
        Route::get('/quiz-types/exam', [QuizTypeController::class, 'exam']);
        Route::get('/my-role-subscriptions', [RoleSubscriptionController::class, 'mySubscriptions']);
        Route::get('/id-cards', [IdCardController::class, 'mine']);
        Route::post('/id-cards', [IdCardController::class, 'store']);
        Route::get('/my-subscriptions', [SubscriptionPurchaseController::class, 'mySubscriptions']);

        // Payment history (Roles + Ads + Ecom + Quiz)
        Route::get('/payment-history', [PaymentHistoryController::class, 'index']);
        // Quiz subscription (user side)
        Route::get('/my-quiz-subscriptions', [QuizSubscriptionController::class, 'mySubscriptions']);
        Route::post('/quiz-subscriptions/purchase', [QuizSubscriptionController::class, 'purchase']);
        Route::post('/quiz-subscriptions/payment/verify', [QuizSubscriptionController::class, 'verifyPayment']);
        Route::post('/quiz-subscriptions/payment/failed', [RoleSubscriptionController::class, 'paymentFailed']);
        Route::post('/role-subscriptions/purchase', [RoleSubscriptionController::class, 'purchase']);
        Route::post('/role-subscriptions/payment/verify', [RoleSubscriptionController::class, 'verifyPayment']);
        Route::post('/role-subscriptions/payment/failed', [RoleSubscriptionController::class, 'paymentFailed']);
        Route::post('/subscription-plans/purchase', [SubscriptionPurchaseController::class, 'purchase'])->defaults('type', 'ads');
        Route::post('/ecom-subscriptions/purchase', [SubscriptionPurchaseController::class, 'purchase'])->defaults('type', 'ecom');
        Route::post('/subscriptions/payment/verify', [SubscriptionPurchaseController::class, 'verify']);
        Route::post('/subscriptions/payment/failed', [RoleSubscriptionController::class, 'paymentFailed']);
        Route::post('/ecom-details', [EcomDetailController::class, 'store']);
        Route::get('/ecom-details/{id}', [EcomDetailController::class, 'show']);
        Route::post('/ecom-details/{id}', [EcomDetailController::class, 'update']);
        Route::patch('/ecom-details/{id}/status', [EcomDetailController::class, 'updateStatus']);
        Route::match(['delete', 'post'], '/ecom-details/{id}', [EcomDetailController::class, 'destroy']);
        // User Quiz APIs
        Route::post('/quiz/start', [QuizController::class, 'startQuiz']);
        Route::post('/quiz/submit', [QuizController::class, 'submitQuiz']);
        Route::get('/quiz/result/{attemptId}', [QuizController::class, 'result']);
        Route::get('/quiz/history', [QuizController::class, 'history']);
        Route::post('/users/follow', [UserSocialController::class, 'follow']);
        Route::post('/users/unfollow', [UserSocialController::class, 'unfollow']);
        Route::get('/users/followers', [UserSocialController::class, 'followers']);
        Route::get('/users/following', [UserSocialController::class, 'following']);
        Route::post('/users/block', [UserSocialController::class, 'block']);
        Route::post('/users/unblock', [UserSocialController::class, 'unblock']);
        Route::get('/users/blocked', [UserSocialController::class, 'blocked']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::match(['delete', 'post'], '/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
        Route::match(['delete', 'post'], '/device-tokens', [DeviceTokenController::class, 'destroy']);


            Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'show']);
            Route::get('/commissions', [WalletController::class, 'commissions']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            Route::get('/withdrawals', [WalletController::class, 'withdrawals']);
            Route::post('/withdrawals', [WalletController::class, 'storeWithdrawal']);
            Route::patch('/withdrawals/{id}/cancel', [WalletController::class, 'cancelWithdrawal']);
        });
    });

    Route::middleware(ResolveOptionalJwt::class)->group(function () {
        Route::get('/news', [NewsController::class, 'index']);
        Route::get('/news/{id}', [NewsController::class, 'show'])->whereNumber('id');
        Route::get('/feeds', [NewsController::class, 'feeds']);
        Route::get('/comments/{newsId}', [CommentController::class, 'index']);   // moved here (public)
        Route::get('/comments/{newsId}/detailed', [CommentInteractionController::class, 'detailed'])->whereNumber('newsId');
        Route::get('/comments/{commentId}/replies', [CommentInteractionController::class, 'indexReplies'])->whereNumber('commentId');
        Route::post('/news/{id}/share', [NewsController::class, 'share'])
        ->middleware('throttle:30,1');    
       
        Route::get('/ads-details', [ads_detailsController::class, 'index']);
        Route::get('/ecom-details', [EcomDetailController::class, 'publicIndex']);
    });
    Route::get('/profile/{id}', [ProfileController::class, 'showPublic']);
    Route::get('/newspapers', [NewspaperController::class, 'index']);
    Route::get('/newspapers/{id}', [NewspaperController::class, 'show'])->whereNumber('id');
    Route::get('/ads-details/{id}', [ads_detailsController::class, 'show']);
    Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
     Route::get('/role-subscription-plans', [RoleSubscriptionController::class, 'index']);
    Route::get('/ecom-subscriptions', [EcomSubscriptionController::class, 'index']);
    Route::get('/quiz-subscription-plans', [QuizSubscriptionController::class, 'index']);
    foreach (['admin-rti', 'rti-admin'] as $adminPrefix) {
        Route::prefix($adminPrefix)->group(function () {
            Route::post('/login', [AdminAuthController::class, 'login']);

            Route::middleware([AuthenticateWithJwt::class, EnsureAdminUser::class])->group(function () {
                Route::get('/me', [AdminAuthController::class, 'me']);
                Route::post('/logout', [AdminAuthController::class, 'logout']);
                Route::get('/dashboard', [AdminAuthController::class, 'dashboard']);
                Route::get('/id-cards', [IdCardController::class, 'adminIndex']);
                Route::patch('/id-cards/{idCard}/status', [IdCardController::class, 'updateStatus']);
                // Dashboard cards
                Route::get('/wallet-overview', [AdminWalletController::class, 'overview']);
                // Wallets
                Route::get('/wallets', [AdminWalletController::class, 'index']);
                Route::get('/wallets/{userId}', [AdminWalletController::class, 'show']);
                Route::post('/wallets/{userId}/adjust', [AdminWalletController::class, 'adjust']);
                Route::patch('/wallets/{userId}/freeze', [AdminWalletController::class, 'freeze']);
                Route::post('/wallets/{userId}/recalculate', [AdminWalletController::class, 'recalculate']);

                Route::match(['delete', 'post'], '/wallet/{id}', [AdminWalletController::class, 'deleteTransaction']);
                Route::match(['delete', 'post'], '/wallet-transactions/{id}', [AdminWalletController::class, 'deleteTransaction']);
                Route::match(['delete', 'post'], '/withdrawals/{id}', [AdminWalletController::class, 'deleteWithdrawal']);
                Route::get('/quiz-attempts', [AdminQuizAttemptController::class, 'index']);
                Route::get('/users/{userId}/quiz-attempts', [AdminQuizAttemptController::class, 'byUser'])->whereNumber('userId');
                Route::match(['delete', 'post'], '/quiz-attempts/{id}', [AdminQuizAttemptController::class, 'destroy'])->whereNumber('id');

                // Commission records
                Route::get('/commissions', [AdminWalletController::class, 'commissions']);
                Route::get('/commissions/{id}', [AdminWalletController::class, 'commissionShow']);
                Route::patch('/commissions/{id}/approve', [AdminWalletController::class, 'approveCommission']);
                Route::patch('/commissions/{id}/reverse', [AdminWalletController::class, 'reverseCommission']);
                Route::post('/commissions/bulk-approve', [AdminWalletController::class, 'bulkApproveCommissions']);
                // Transaction ledger
                Route::get('/wallet-transactions', [AdminWalletController::class, 'transactions']);
                // Withdrawals
                Route::get('/withdrawals', [AdminWalletController::class, 'withdrawals']);
                Route::get('/withdrawals/{id}', [AdminWalletController::class, 'withdrawalShow']);
                Route::patch('/withdrawals/{id}/approve', [AdminWalletController::class, 'approveWithdrawal']);
                Route::patch('/withdrawals/{id}/mark-paid', [AdminWalletController::class, 'markWithdrawalPaid']);
                Route::patch('/withdrawals/{id}/reject', [AdminWalletController::class, 'rejectWithdrawal']);
                Route::match(['delete', 'post'], '/wallet/{id}', [AdminWalletController::class, 'deleteTransaction']);
                Route::match(['delete', 'post'], '/wallet-transactions/{id}', [AdminWalletController::class, 'deleteTransaction']);
                Route::match(['delete', 'post'], '/withdrawals/{id}', [AdminWalletController::class, 'deleteWithdrawal']);
                Route::get('/quiz-attempts', [AdminQuizAttemptController::class, 'index']);
                Route::get('/users/{userId}/quiz-attempts', [AdminQuizAttemptController::class, 'byUser'])->whereNumber('userId');
                Route::match(['delete', 'post'], '/quiz-attempts/{id}', [AdminQuizAttemptController::class, 'destroy'])->whereNumber('id');                
                Route::get('/users', [AdminUserController::class, 'index']);
                Route::get('/users/pending-profiles', [AdminUserController::class, 'pendingProfiles']);
                Route::post('/users', [AdminUserController::class, 'store']);
                Route::get('/users/{user}', [AdminUserController::class, 'show']);


              // PUT/PATCH ke saath POST bhi allowed hai: kai shared hosts/WAF PUT & PATCH par 403 dete hain.
                Route::match(['put', 'patch', 'post'], '/users/{user}', [AdminUserController::class, 'update']);
                Route::match(['put', 'patch', 'post'], '/users-status/{user}', [AdminUserController::class, 'updateStatus']);
                Route::match(['put', 'patch', 'post'], '/users-approve-profile/{user}', [AdminUserController::class, 'approveProfile']);
                Route::match(['put', 'patch', 'post'], '/users/{user}/approve-profile', [AdminUserController::class, 'approveProfile']);
                Route::match(['put', 'patch', 'post'], '/users/{user}/reject-profile', [AdminUserController::class, 'rejectProfile']);
                Route::match(['delete', 'post'], '/users/{user}', [AdminUserController::class, 'destroy']);
                Route::get('/user-follows', [AdminUserSocialController::class, 'follows']);
                Route::match(['delete', 'post'], '/user-follows/{id}', [AdminUserSocialController::class, 'destroyFollow']);
                Route::get('/user-blocks', [AdminUserSocialController::class, 'blocks']);
                Route::match(['delete', 'post'], '/user-blocks/{id}', [AdminUserSocialController::class, 'destroyBlock']);
                // Route::get('/referrals', [ReferralController::class, 'adminIndex']);
                // Route::get('/users/{userId}/referrals', [ReferralController::class, 'adminUserWise']);
                Route::get('/news', [AdminNewsController::class, 'index']);
                Route::get('/reports', [AdminReportController::class, 'index']);
                Route::get('/reports/{id}', [AdminReportController::class, 'show'])->whereNumber('id');
                Route::patch('/reports/{id}/status', [AdminReportController::class, 'updateStatus'])->whereNumber('id');
                Route::get('/news/{id}', [AdminNewsController::class, 'show']);
                Route::put('/news/{id}', [AdminNewsController::class, 'update']);
                Route::patch('/news/{id}/status', [AdminNewsController::class, 'updateStatus']);
                Route::match(['delete', 'post'], '/news/{id}', [AdminNewsController::class, 'destroy']);
                Route::get('/newspapers', [NewspaperController::class, 'adminIndex']);
                Route::post('/newspapers', [NewspaperController::class, 'store']);
                Route::put('/newspapers/{id}', [NewspaperController::class, 'update'])->whereNumber('id');
                Route::match(['patch', 'post'], '/newspapers/{id}/publish', [NewspaperController::class, 'publish'])->whereNumber('id');
                Route::match(['delete', 'post'], '/newspapers/{id}', [NewspaperController::class, 'destroy'])->whereNumber('id');
                Route::get('/quiz-types', [QuizTypeController::class, 'adminIndex']);
                Route::post('/quiz-types', [QuizTypeController::class, 'adminStore']);
                Route::get('/quiz-types/{id}', [QuizTypeController::class, 'adminShow']);
                Route::put('/quiz-types/{id}', [QuizTypeController::class, 'adminUpdate']);
                Route::match(['delete', 'post'], '/quiz-types/{id}', [QuizTypeController::class, 'adminDestroy']);
                Route::patch('/quiz-types/{id}/restore', [QuizTypeController::class, 'adminRestore']);
                // Admin
                Route::get('/referrals', [ReferralController::class, 'adminIndex']);
                Route::get('/referrals/{userId}', [ReferralController::class, 'adminUserWise']);
                Route::get('/referral-commission-rates', [ReferralCommissionRateController::class, 'index']);
                Route::put('/referral-commission-rates/{level}', [ReferralCommissionRateController::class, 'update']);
                Route::get('/question-ans', [QuestionAnsController::class, 'adminIndex']);
                Route::post('/question-ans', [QuestionAnsController::class, 'adminStore']);
                Route::put('/question-ans/{quiz_type_id}', [QuestionAnsController::class, 'adminUpdate']);
                Route::match(['delete', 'post'], '/question-ans/{id}', [QuestionAnsController::class, 'adminDestroy']);
                Route::patch('/question-ans/{id}/restore', [QuestionAnsController::class, 'adminRestore']);

                Route::get('/ads-details', [ads_detailsController::class, 'adminIndex']);
                Route::post('/ads-details', [ads_detailsController::class, 'adminStore']);
                Route::get('/ads-details/{id}', [ads_detailsController::class, 'adminShow']);
                Route::put('/ads-details/{id}', [ads_detailsController::class, 'adminUpdate']);
                Route::patch('/ads-details/{id}/status', [ads_detailsController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/ads-details/{id}', [ads_detailsController::class, 'adminDestroy']);
                Route::patch('/ads-details/{id}/restore', [ads_detailsController::class, 'adminRestore']);
                Route::get('/ads-views', [ads_viewController::class, 'adminIndex']);
                Route::get('/ads-views/totals', [ads_viewController::class, 'adminTotals']);
                Route::get('/ads-views/{id}', [ads_viewController::class, 'adminShow']);
                Route::get('/subscription-plans', [SubscriptionPlanController::class, 'adminIndex']);
                Route::post('/subscription-plans', [SubscriptionPlanController::class, 'adminStore']);
                Route::get('/subscription-plans/{id}', [SubscriptionPlanController::class, 'adminShow']);
                Route::put('/subscription-plans/{id}', [SubscriptionPlanController::class, 'adminUpdate']);
                Route::get('/subscription-plans/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'ads');
                Route::get('/subscription-plans/{id}/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'ads');
                Route::patch('/subscription-plans/{id}/status', [SubscriptionPlanController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/subscription-plans/{id}', [SubscriptionPlanController::class, 'adminDestroy']);
                Route::patch('/subscription-plans/{id}/restore', [SubscriptionPlanController::class, 'adminRestore']);
                Route::get('/role-subscription-plans', [RoleSubscriptionController::class, 'adminIndex']);
                Route::post('/role-subscription-plans', [RoleSubscriptionController::class, 'adminStore']);
                Route::get('/role-subscription-plans/{id}', [RoleSubscriptionController::class, 'adminShow']);
                Route::put('/role-subscription-plans/{id}', [RoleSubscriptionController::class, 'adminUpdate']);
                Route::patch('/role-subscription-plans/{id}', [RoleSubscriptionController::class, 'adminUpdate']);
                Route::patch('/role-subscription-plans/{id}/status', [RoleSubscriptionController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/role-subscription-plans/{id}', [RoleSubscriptionController::class, 'adminDestroy']);
                Route::patch('/role-subscription-plans/{id}/restore', [RoleSubscriptionController::class, 'adminRestore']);
                Route::get('/ecom-subscriptions', [EcomSubscriptionController::class, 'adminIndex']);
                
                Route::get('/subscription-purchases', [SubscriptionPurchaseController::class, 'adminIndex']);
                Route::post('/ecom-subscriptions', [EcomSubscriptionController::class, 'adminStore']);
                Route::get('/ecom-subscriptions/{id}', [EcomSubscriptionController::class, 'adminShow']);
                Route::get('/ecom-subscriptions/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'ecom');
                Route::get('/ecom-subscriptions/{id}/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'ecom');
                Route::put('/ecom-subscriptions/{id}', [EcomSubscriptionController::class, 'adminUpdate']);
                Route::patch('/ecom-subscriptions/{id}/status', [EcomSubscriptionController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/ecom-subscriptions/{id}', [EcomSubscriptionController::class, 'adminDestroy']);
                // Quiz subscription plans (admin) + purchasers
                Route::get('/quiz-subscription-plans', [QuizSubscriptionController::class, 'adminIndex']);
                Route::post('/quiz-subscription-plans', [QuizSubscriptionController::class, 'adminStore']);
                Route::get('/quiz-subscription-plans/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'quiz');
                Route::get('/quiz-subscription-plans/{id}/purchasers', [PaymentHistoryController::class, 'purchasers'])->defaults('module', 'quiz');
                Route::get('/quiz-subscription-plans/{id}', [QuizSubscriptionController::class, 'adminShow']);
                Route::put('/quiz-subscription-plans/{id}', [QuizSubscriptionController::class, 'adminUpdate']);
                Route::patch('/quiz-subscription-plans/{id}', [QuizSubscriptionController::class, 'adminUpdate']);
                Route::patch('/quiz-subscription-plans/{id}/status', [QuizSubscriptionController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/quiz-subscription-plans/{id}', [QuizSubscriptionController::class, 'adminDestroy']);
                Route::patch('/quiz-subscription-plans/{id}/restore', [QuizSubscriptionController::class, 'adminRestore']);
                // Admin payment history (?user_id= se user-wise)
                Route::get('/payment-history', [PaymentHistoryController::class, 'adminIndex']);
                Route::get('/ecom-details', [EcomDetailController::class, 'adminIndex']);
                Route::post('/ecom-details', [EcomDetailController::class, 'adminStore']);
                Route::get('/ecom-details/{id}', [EcomDetailController::class, 'adminShow']);
                Route::put('/ecom-details/{id}', [EcomDetailController::class, 'adminUpdate']);
                Route::patch('/ecom-details/{id}/status', [EcomDetailController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/ecom-details/{id}', [EcomDetailController::class, 'adminDestroy']);
                Route::get('/ecom-enquiries', [EcomEnquiryController::class, 'adminIndex']);
                Route::get('/ecom-enquiries/{id}', [EcomEnquiryController::class, 'adminShow']);
                Route::patch('/ecom-enquiries/{id}/status', [EcomEnquiryController::class, 'adminUpdateStatus']);
                Route::match(['delete', 'post'], '/ecom-enquiries/{id}', [EcomEnquiryController::class, 'adminDestroy']);
                Route::post('/notifications/broadcast', [AdminNotificationController::class, 'broadcast']);
            });
        });
    }
};

$registerRoutes();

Route::prefix('api')->group($registerRoutes);
