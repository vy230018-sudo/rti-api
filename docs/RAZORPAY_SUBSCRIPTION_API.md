# Razorpay role subscription payment API

All endpoints below require `Authorization: Bearer <JWT_TOKEN>`. The plan ID must be from `GET /api/role-subscription-plans`.

`POST /api/role-subscriptions/purchase` creates a Razorpay order and records a `created` row in `subscription_payments`. It does **not** activate credits.

```bash
curl -X POST "http://localhost:8000/api/role-subscriptions/purchase" \
  -H "Authorization: Bearer <JWT_TOKEN>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"plan_id":1}'
```

Open Razorpay Checkout on the client using `razorpay.key`, `razorpay.order_id`, `razorpay.amount`, and `razorpay.currency` from that response. On a successful Checkout response, send its three Razorpay fields to the server. The server verifies the HMAC signature before creating the `user_subscription` row. Only then are the plan credits active.

```bash
curl -X POST "http://localhost:8000/api/role-subscriptions/payment/verify" \
  -H "Authorization: Bearer <JWT_TOKEN>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"razorpay_order_id":"order_xxx","razorpay_payment_id":"pay_xxx","razorpay_signature":"signature_from_razorpay"}'
```

For Checkout failure/cancellation, record it in the same payment table:

```bash
curl -X POST "http://localhost:8000/api/role-subscriptions/payment/failed" \
  -H "Authorization: Bearer <JWT_TOKEN>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"razorpay_order_id":"order_xxx","error_code":"BAD_REQUEST_ERROR","error_description":"Payment cancelled"}'
```

Check an activated subscription and its `total_credits`, `used_credits`, and `remaining_credits`:

```bash
curl "http://localhost:8000/api/my-role-subscriptions" \
  -H "Authorization: Bearer <JWT_TOKEN>" \
  -H "Accept: application/json"
```
