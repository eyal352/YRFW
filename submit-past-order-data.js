// The purpose of this file is to submit past order data that is available in JSON format
// The orders were exported and formatted with the Woo Order Export Lite extension (https://wordpress.org/plugins/woo-order-export-lite/)|

// To use, export the orders with the extension into a JSON format. 
// The JSON object of orders should then be given the variable name WcOrders and placed within the same context of this file
// Replace ###API_KEY_HERE### and ###API_SECRET_HERE### with the correct values (without the hash) and run

async function postOrderToYotpo(data = {}) {
    console.log(JSON.stringify(data))
    console.log(data);
    orderObj = data
    const response = await fetch('https://api.yotpo.com/core/v3/stores/###API_KEY_HERE###/orders', {
        method: 'POST',
        mode: 'cors',
        cache: 'no-cache',
        headers: {
            'Content-Type': 'application/json',
            'X-Yotpo-Token': '###API_SECRET_HERE###',
        },
 // no-referrer, *no-referrer-when-downgrade, origin, origin-when-cross-origin, same-origin, strict-origin, strict-origin-when-cross-origin, unsafe-url
        body: JSON.stringify(data) // body data type must match "Content-Type" header
    });
    return response.json(); // parses JSON response into native JavaScript objects
}


WcOrders.forEach(order => {
    if (order.order_status == "Completed") {
        //console.log(order)
        let orderObject = {
            "order": {
                "external_id": order.order_number,
                "order_date": order.order_date.replace(" ", "T").concat(":00Z"),
                "customer": {
                    "external_id": order.customer_user,
                    "email": order.billing_email,
                    "first_name": order.billing_first_name,
                    "last_name": order.billing_last_name,
                    "accepts_sms_marketing": order.accept_sms_marketing == "1" ? true : false,
                    "accepts_email_marketing": true
                },
                "line_items": '',
                "fulfillments": [{
                    "fulfillment_date": order.order_date.replace(" ", "T").concat(":00Z"),
                    "external_id": order.order_number,
                    "status": "success",
                    'fulfilled_items': ''
                }]
            }
        }

        let prod = [];
        order.products.forEach(product => {
            prod.push({
                "quantity": product.qty,
                "external_product_id": product.product_id
            })
        });
        orderObject['order']['line_items'] = prod;
        orderObject['order']['fulfillments'][0]['fulfilled_items'] = prod;

        //console.log(orderObject);
        postOrderToYotpo(orderObject)
            .then(data => {
                console.log(data); // JSON data parsed by `data.json()` call
            });
    };
})

