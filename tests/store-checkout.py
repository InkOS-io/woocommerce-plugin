#!/usr/bin/env python3
"""Place one synthetic order through a local WooCommerce Store API and a test bank-transfer gateway."""
import argparse
import http.cookiejar
import json
import urllib.error
import urllib.parse
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("url")
    parser.add_argument("lines", nargs="+", metavar="PRODUCT_ID:QUANTITY")
    args = parser.parse_args()
    if urllib.parse.urlsplit(args.url).hostname not in {"127.0.0.1", "localhost", "::1"}:
        parser.error("Use a loopback WooCommerce sandbox with synthetic products.")
    origin = args.url.rstrip("/")
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )
    cart_token = ""

    def request(path, body=None, form=False):
        nonlocal cart_token
        request_headers = {}
        encoded = None
        if body is not None:
            if form:
                encoded = urllib.parse.urlencode(body).encode()
                request_headers["Content-Type"] = "application/x-www-form-urlencoded"
            else:
                encoded = json.dumps(body).encode()
                request_headers["Content-Type"] = "application/json"
        if cart_token:
            request_headers["Cart-Token"] = cart_token
        req = urllib.request.Request(origin + path, encoded, headers=request_headers)
        try:
            with opener.open(req, timeout=120) as res:
                cart_token = res.headers.get("Cart-Token", cart_token)
                return json.load(res)
        except urllib.error.HTTPError as exc:
            response = json.load(exc)
            raise SystemExit(f"HTTP {exc.code}: {response.get('message', response)}") from exc

    api = "/?rest_route=/wc/store/v1/"
    request(api + "cart")
    expected = 0
    for line in args.lines:
        product, qty = map(int, line.split(":"))
        preview = request("/?wc-ajax=inkos_price", {"product": product, "quantity": qty}, True)
        if not preview["success"] or not preview["data"]["mapped"]:
            raise SystemExit("The product preview failed. Check the mapping and connection.")
        price = preview["data"]
        print(f"Product {product}: {price['formatted']} for {qty}")
        cart = request(api + "cart/add-item", {"id": product, "quantity": qty})
        item = next(x for x in cart["items"] if x["id"] == product and x["quantity"] == qty)
        if int(item["totals"]["line_total"]) != price["totalMinor"] or item["totals"]["currency_code"] != price["currency"]:
            raise SystemExit("Cart line differs from its shopper-facing preview.")
        expected += price["totalMinor"]
        if int(cart["totals"]["total_price"]) != expected:
            raise SystemExit("Cart total does not match the sum of print lines.")
        print(f"  Cart line {item['totals']['line_total']} minor units")
    address = {
        "first_name": "Print", "last_name": "Fixture", "email": "fixture@example.test",
        "address_1": "100 Test Street", "city": "Toronto", "state": "ON",
        "postcode": "M5V 2T6", "country": "CA", "phone": "4165550100",
    }
    result = request(api + "checkout", {
        "billing_address": address, "payment_method": "bacs", "payment_data": [],
        "additional_fields": {}, "customer_note": "Synthetic integration test",
    })
    if result["payment_result"]["payment_status"] != "success":
        raise SystemExit("The sandbox gateway did not accept the order.")
    print(f"WooCommerce order {result['order_id']} accepted at {expected} minor units.")


if __name__ == "__main__":
    main()
