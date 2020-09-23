<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

DEFINE('HOST', '127.0.0.1');
DEFINE('USER', 'root');
DEFINE('PASSWORD', 'a');
DEFINE('DB_NAME', 'shop');
DEFINE('ZARRIN', '2fca4aec-080f-11e8-9daa-000c295eb8fc');


session_start();


$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($loader);
$config = [
    'settings' => [
        'displayErrorDetails' => true,
        'logger' => [
            'name' => 'slim-app',
            'level' => Monolog\Logger::DEBUG,
            'path' => __DIR__ . '/../logs/app.log',
        ],
    ],
];
$settings = array();
$app = new \Slim\App($config);

$app->get('/', function (Request $request, Response $response, $args) use ($twig, $app) {
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $response->getBody()->write($twig->render('home.twig', [
                "common" => $settings,
                "new_products" => getNewProducts($conn),
                "tree" => getTree($conn),
                "home_categs" => getHomeCategories($conn),
                "user" => getCurrentUser()
    ]));
    $conn->close();
    return;
})->setName('home');

$app->get('/order', function (Request $request, Response $response, $args) use ($twig, $app) {
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $response->getBody()->write($twig->render('order.twig', [
                "common" => $settings,
                "tree" => getTree($conn),
                "user" => getCurrentUser()
    ]));
    $conn->close();
    return;
})->setName('order');

$app->post('/save_order', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    } else {
        $orders = json_decode($_POST["orders"]);
        if (count($orders) == 0) {
            return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                                "message" => "هیچ محصولی در سبد خرید موجود نیست"
            ]));
        }
        $id = getCurrentUser()["id"];
        $conn = getConnection();
        $total = 0;
        $ourGain = 0;
        $forProducers = array();
        $orderIds = "";
        foreach ($orders as $order) {
            $products = $order->products;
            $orderTotal = 0;
            $zp_code = null;
            foreach ($products as $product) {
                $stmt = $conn->prepare("SELECT sell_price,buy_price,user.zp_code FROM product LEFT JOIN user ON product.producer=user.id WHERE product.id=?");
                $stmt->bind_param("i", $product->id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $sell_price = $product->amount * $row["sell_price"];
                $buy_price = $product->amount * $row["buy_price"];
                $ourGain += ($sell_price - $buy_price);
                $zp_code = $row["zp_code"];
                $stmt->close();
                if (array_key_exists($zp_code, $forProducers)) {
                    $forProducers[$zp_code] = $forProducers[$zp_code] + $buy_price;
                } else {
                    $forProducers[$zp_code] = $buy_price;
                }
                $orderTotal += $sell_price;
            }

            $code = randomPassword(10);
            $address_id = $order->address_id;
            $producer_id = $order->producer_id;
            $stmt = $conn->prepare("INSERT INTO orders(address_id,code,customer_id,producer_id,total)VALUES(?,?,?,?,?);");
            $stmt->bind_param("isiii", $address_id, $code, $id, $producer_id, $orderTotal);
            $stmt->execute();
            $order_id = $conn->insert_id;
            $error = $stmt->error;
            $stmt->close();
            foreach ($products as $product) {
                $stmt = $conn->prepare("INSERT INTO order_product(order_id,product_id,amount)VALUES(?,?,?);");
                $product_id = $product->id;
                $amount = $product->amount;
                $stmt->bind_param("iii", $order_id, $product_id, $amount);
                $stmt->execute();
                $stmt->close();
            }
            $orderIds = $orderIds . "," . $order_id;
            $total += $orderTotal;
        }
        $orderIds = ltrim($orderIds, ",");
        $additionlData = array();
        foreach ($forProducers as $key => $value) {
            $item = array();
            $item["amount"] = $value;
            $item["description"] = "واریز وجه خرید";
            $item["iban"] = $key;
            array_push($additionlData, $item);
        }
        $total = 1500;
        $ourGain = 500;

        $stmt = $conn->prepare("SELECT zp_code,percent FROM user WHERE percent IS NOT NULL;");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $percent = $row["percent"];
            $acc = $row["zp_code"];
            $ourItem = array();
            $ourItem["amount"] = (int) ($percent * $ourGain / 100);
            $ourItem["description"] = "واریز سود تراکنش";
            $ourItem["iban"] = $acc;
            array_push($additionlData, $ourItem);
        }

        $stmt->close();
        $payload = array();
        $payload["wages"] = $additionlData;
        $data = array('merchant_id' => ZARRIN,
            'amount' => $total * 10,
            'callback_url' => 'http://localhost/choobi/verify',
            'description' => 'خرید از فروشگاه',
            'additional_data' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        $jsonData = json_encode($data);
        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);
        $_SESSION["order_ids"] = $orderIds;
        $_SESSION["order_amount"] = $total*10;
        if ($err) {
            $response->getBody()->write("cURL Error #:" . $err);
            return;
        } else {
            if ($result["data"]["code"] == 100) {
                return $response->withRedirect('https://www.zarinpal.com/pg/StartPay/' . $result["data"]["authority"]);
            } else {
                $response->getBody()->write('ERR: ' . $result["error"]["code"]);
                return;
            }
        }
        $conn->close();
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('home'));
    }
})->setName('save-order');

$app->get('/verify', function (Request $request, Response $response, $args) use ($twig, $app) {
    global $settings;
    $orderIds = $_SESSION["order_ids"];
    $amount = $_SESSION["order_amount"];
    $auth = $request->getQueryParams()["Authority"];
    $data = array('merchant_id' => ZARRIN, 'authority' => $auth, 'amount' => $amount);
    $jsonData = json_encode($data);
    $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
    curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ));
    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    if ($err) {
        $response->getBody()->write($err);
        return;
    } else {
        if ($result["data"]['code'] == 100) {
            $paycode = $result["data"]['ref_id'];
            $conn = getConnection();
            $stmt = $conn->prepare("UPDATE orders SET paycode=? WHERE id IN (" . $orderIds . ");");
            $stmt->bind_param("s", $paycode);
            $stmt->execute();
            $error = $stmt->error;
            $stmt->close();
            $conn->close();
            $response->getBody()->write($twig->render('receipt.twig', [
                        "common" => $settings,
                        "order" => $orderIds,
                        "paycode" => $paycode
            ]));
            return;
        } 
        if ($result["data"]['code'] == 101) {
            $response->getBody()->write($twig->render('payerror.twig', [
                        "common" => $settings,
                        "error" => " تراکنش تکراری است. احتمالا صفحه را refresh کرده اید."
            ]));
            return;
        }
        else {
            $response->getBody()->write($twig->render('payerror.twig', [
                        "common" => $settings,
                        "error" => $result["error"]['code']
            ]));
            return;
        }
    }
})->setName('verify');

$app->get('/password', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    }
    global $settings;
    $conn = getConnection();
    loadSettings($conn);
    $response->getBody()->write($twig->render('password.twig',
                    [
                        "common" => $settings,
                        "tree" => getTree($conn),
                        "home_categs" => getHomeCategories($conn),
                        "user" => getCurrentUser()
                    ]
    ));
    $conn->close();
    return;
})->setName('password');

$app->get('/address', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    }
    global $settings;
    $conn = getConnection();
    $redirect = "";
    if (isset($request->getQueryParams()["redirect"])) {
        $redirect = $request->getQueryParams()["redirect"];
    }
    loadSettings($conn);
    $stmt = $conn->prepare("SELECT * FROM address WHERE customer_id=?");
    $stmt->bind_param("i", getCurrentUser()["id"]);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        array_push($list, $row);
    }
    $stmt->close();
    $response->getBody()->write($twig->render('address.twig',
                    [
                        "common" => $settings,
                        "tree" => getTree($conn),
                        "home_categs" => getHomeCategories($conn),
                        "user" => getCurrentUser(),
                        "addresses" => $list,
                        "redirect" => $redirect
                    ]
    ));
    $conn->close();
    return;
})->setName('address');

$app->post('/change-current-password', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    } else {
        $password = $_POST["password"];
        $passwordRep = $_POST["passwordRep"];
        if ($password != $passwordRep) {
            return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                                "message" => "رمز عبور با تکرارش همخوانی ندارد"
            ]));
        }
        $id = getCurrentUser()["id"];
        $conn = getConnection();
        $stmt = $conn->prepare("UPDATE user SET password=? WHERE id=?");
        $password = hashPassword($password);
        $stmt->bind_param("si", $password, $id);
        $stmt->execute();
        $conn->close();
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('home'));
    }
})->setName('change-current-password');

$app->get('/profile', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    }
    global $settings;
    $conn = getConnection();
    loadSettings($conn);
    $response->getBody()->write($twig->render('profile.twig',
                    [
                        "common" => $settings,
                        "tree" => getTree($conn),
                        "home_categs" => getHomeCategories($conn),
                        "user" => getCurrentUser()
                    ]
    ));
    $conn->close();
    return;
})->setName('profile');

$app->post('/save-profile', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "ابتدا باید وارد شوید"
        ]));
    } else {
        $fname = $_POST["fname"];
        $lname = $_POST["lname"];
        $email = $_POST["email"];
        $mobile = $_POST["mobile"];
        $id = getCurrentUser()["id"];
        $conn = getConnection();
        $stmt = $conn->prepare("UPDATE user SET fname=?,lname=?,email=?,mobile=? WHERE id=?");
        $stmt->bind_param("ssssi", $fname, $lname, $email, $mobile, $id);
        $stmt->execute();
        $conn->close();
        updateCurrentUser($fname, $lname, $email, $mobile);
        return $response->withRedirect($app->getContainer()->get('router')->pathFor('error', [], [
                            "message" => "پروفایل شما با موفقیت ویرایش شد."
        ]));
    }
})->setName('save-profile');

$app->get('/error', function (Request $request, Response $response, $args) use ($twig, $app) {
    global $settings;
    $conn = getConnection();
    loadSettings($conn);
    $message = $request->getQueryParams()["message"];
    $response->getBody()->write($twig->render('error.twig',
                    [
                        "common" => $settings,
                        "tree" => getTree($conn),
                        "home_categs" => getHomeCategories($conn),
                        "user" => getCurrentUser(),
                        "message" => $message
                    ]
    ));
    $conn->close();
    return;
})->setName('error');

$app->get('/{image_type}/{image_name}-{image_id}-jpg', function (Request $request, Response $response, $args) use ($twig, $app) {
    $imageType = trim(urldecode($args["image_type"]));
    $imageId = trim(urldecode($args["image_id"]));
    $imageName = trim(urldecode($args["image_name"]));
    $file = __DIR__ . '/img/products/' . $imageType . '/' . $imageId . '.jpg';
    $fh = fopen($file, 'rb');
    $stream = new \Slim\Http\Stream($fh);
    return $response->withHeader('Content-Type', 'image/jpeg')
                    ->withBody($stream);
})->setName('image');

$app->get('/{categ_name}-{categ_id}-html', function (Request $request, Response $response, $args) use ($twig, $app) {
    $categ_name = trim(urldecode($args["categ_name"]));
    $categ_id = trim(urldecode($args["categ_id"]));
    $conn = getConnection();
    global $settings;
    loadSettings($conn);

    $stmt = $conn->prepare("SELECT * from category WHERE id=?");
    $stmt->bind_param("i", intval($categ_id));
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $metaDesc = $row["meta_desc"];
    $keywords = $row["keywords"];
    $stmt->close();


    $response->getBody()->write($twig->render('categ.twig', [
                "common" => $settings,
                "meta_desc" => $metaDesc,
                "keywords" => $keywords,
                "products" => getCategoryProducts($conn, $categ_id),
                "id" => $categ_id,
                "url" => $categ_name . "-" . $categ_id . "-html",
                "name" => str_replace("-", " ", $categ_name),
                "tree" => getTree($conn),
                "user" => getCurrentUser(),
                "range" => getMinMax($conn, $categ_id)
    ]));
    $conn->close();
    return;
})->setName('categ');

$app->get('/categ_fields', function (Request $request, Response $response, $args) use ($twig, $app) {
    $id = $request->getQueryParams()["id"];
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $stmt = $conn->prepare("SELECT DISTINCT(product_field.field_id) AS id,field.name,field.min_value,field.max_value,field.unit,field.type,field_value.id AS value_id,field_value.value FROM product_field LEFT JOIN field ON product_field.field_id=field.id LEFT JOIN field_value ON field_value.field_id=field.id LEFT JOIN product ON product.id=product_field.product_id LEFT JOIN category on product.category_id=category.id WHERE (product.category_id=? OR product.category_id IN (SELECT child_id FROM category_children WHERE id=?)) AND field.filterable=1");
    $iid = intval($id);
    $stmt->bind_param("ii", $iid, $iid);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        array_push($list, $row);
    }
    $stmt->close();
    $conn->close();
    return $response->withJson($list);
})->setName('categ_fields');

$app->post('/filter-products', function (Request $request, Response $response, $args) use ($twig, $app) {
    $categ_id = $_POST["categ_id"];
    $sort_field = $_POST["sort_field"];
    $sort_direction = $_POST["sort_direction"];
    $min_price = $_POST["min_price"];
    $max_price = $_POST["max_price"];
    $rangeFilters = array();
    $valueFilters = array();
    if (isset($_POST["range_filters"])) {
        $rangeFilters = $_POST["range_filters"];
    }
    if (isset($_POST["value_filters"])) {
        $valueFilters = $_POST["value_filters"];
    }
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $res = filterCategoryProducts($conn, $categ_id, $sort_field, $sort_direction, $min_price, $max_price, $rangeFilters, $valueFilters);
    $conn->close();
    return $response->withJson($res);
})->setName('filter-products');

$app->get('/{product_name}-{product_id}-htm', function (Request $request, Response $response, $args) use ($twig, $app) {
    global $settings;
    $conn = getConnection();
    loadSettings($conn);
    $product_name = trim(urldecode($args["product_name"]));
    $product_id = trim(urldecode($args["product_id"]));
    $response->getBody()->write($twig->render('product.twig', getProductDetails($conn, $product_id)));
    $conn->close();
    return;
})->setName('product');

$app->get('/categs', function (Request $request, Response $response, $args) use ($twig, $app) {
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $response->getBody()->write(json_encode(getHomeCategories($conn)));
    return;
})->setName('categs');

$app->get('/search/{query}', function (Request $request, Response $response, $args) use ($twig, $app) {
    $conn = getConnection();
    $query = trim(urldecode($args["query"]));
    $param = "%" . $query . "%";
    $stmt = $conn->prepare("SELECT id,name,code FROM product where name LIKE ? LIMIT 10");
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        if (isset($row["code"])) {
            $row["text"] = $row["name"] . "<span style='color:#4cd964;font-size:10pt;'> " . $row["code"] . "</span>";
        }
        array_push($list, $row);
    }
    return $response->withJson($list, 200);
})->setName('search');

$app->post('/dologin', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (isLogged()) {
        return $response->withJson(["error" => "شما قبلا وارد شده اید"], 500);
    } else {
        $email = $_POST["email"];
        $password = $_POST["password"];
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT * FROM user WHERE email=? AND password=?");
        $password = hashPassword($password);
        $stmt->bind_param("ss", $email, $password);
        $stmt->execute();
        $result = $stmt->get_result();
        if (mysqli_num_rows($result) != 0) {
            $row = $result->fetch_assoc();
            setCurrentUser($row);
            $stmt->close();
            $conn->close();
            return $response->withJson(["user" => getCurrentUser()], 200);
        } else {
            $stmt->close();
            $conn->close();
            return $response->withJson(["error" => "نام کاربری یا رمز اشتباه است"], 500);
        }
    }
})->setName('dologin');

$app->post('/save_user', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (isLogged()) {
        return $response->withJson(["error" => "شما قبلا وارد شده اید"], 500);
    } else {
        $fname = $_POST["fname"];
        $lname = $_POST["lname"];
        $email = $_POST["email"];
        $password = $_POST["password"];
        $passwordRep = $_POST["passwordRep"];
        if ($password != $passwordRep) {
            return $response->withJson(["error" => "رمز عبور با تکرارش همخوانی ندارد"], 500);
        }
        $mobile = $_POST["mobile"];
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT * FROM user WHERE email=? OR mobile=?");
        $stmt->bind_param("ss", $email, $mobile);
        $stmt->execute();
        $result = $stmt->get_result();
        if (mysqli_num_rows($result) == 0) {
            $stmt = $conn->prepare("INSERT INTO user(fname,lname,email,mobile,password)VALUES(?,?,?,?,?)");
            $pass = hashPassword($password);
            $stmt->bind_param("sssss", $fname, $lname, $email, $mobile, $pass);
            $stmt->execute();
            $last_id = $conn->insert_id;
            if ($last_id == 0) {
                return $response->withJson(["error" => "خطا در ثبت نام"], 500);
            }
            $row = ["id" => $last_id, "fanme" => $fname, "lname" => $lname, "email" => $email, "password" => $password, "mobile" => $mobile];
            setCurrentUser($row);
            $stmt->close();
            $conn->close();
            return $response->withJson($row, 200);
        } else {
            $stmt->close();
            $conn->close();
            return $response->withJson(["error" => "شماره موبایل یا رایانامه قبلا ثبت شده است"], 500);
        }
    }
})->setName('save_user');

$app->post('/forget', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (isLogged()) {
        return $response->withJson(["error" => "شما قبلا وارد شده اید"], 500);
    } else {
        global $settings;
        $conn = getConnection();
        loadSettings($conn);
        $email = $_POST["email"];
        $stmt = $conn->prepare("SELECT * FROM user WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if (mysqli_num_rows($result) != 0) {
            $hash = randomPassword(15);
            $stmt = $conn->prepare("UPDATE user SET change_pass_hash=? WHERE email=?");
            $stmt->bind_param("ss", $hash, $email);
            $stmt->execute();
            return $response->withJson(mysqli_error($conn), 200);
            $stmt->close();
            $conn->close();
            $message = "برای بازیابی رمز " . " <a href='" . $settings["app_domain"] . "/recover_password/" . $hash . "'>اینجا</a>" . " کلیک کنید.";
            sendMail($email, $message, "بازیابی رمز عبور", $twig);
            return $response->withJson("ok", 200);
        } else {
            $stmt->close();
            $conn->close();
            return $response->withJson(["error" => "چنین ایمیلی در سیستم ثبت نشده است"], 500);
        }
    }
})->setName('forget');

$app->get('/recover_password-{hash}', function (Request $request, Response $response, $args) use ($twig, $app) {
    $hash = trim(urldecode($args["hash"]));
    $conn = getConnection();
    global $settings;
    loadSettings($conn);
    $response->getBody()->write($twig->render('change_pass.twig', [
                "common" => $settings,
                "tree" => getTree($conn),
                "hash" => $hash
    ]));
    $conn->close();
})->setName('recover_password');

$app->post('/change_pass', function (Request $request, Response $response, $args) use ($twig, $app) {
    $password = ($_POST["password"]);
    $passwordRep = ($_POST["passwordRep"]);
    $hash = $_POST["hash"];
    if ($password != $passwordRep) {
        return $response->withJson(["error" => "رمز عبور با تکرارش همخوانی ندارد."], 500);
    }
    $password = hashPassword($password);
    $conn = getConnection();
    $stmt = $conn->prepare("UPDATE user SET password=?,change_pass_hash=NULL WHERE change_pass_hash=?");
    $stmt->bind_param("ss", $password, $hash);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $conn->close();
    return $response->withJson("ok", 200);
})->setName('change_pass');

$app->post('/save_address', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withJson(["error" => "ابتدا باید وارد شوید"], 500);
    } else {
        $id = (int) ($_POST["id"]);
        $address = $_POST["address"];
        $postal_code = $_POST["postal_code"];
        $phone = $_POST["phone"];
        $mobile = $_POST["mobile"];
        $customer_id = getCurrentUser()["id"];
        $conn = getConnection();
        if ($id == -1) {
            $stmt = $conn->prepare("INSERT INTO address(address,postal_code,phone,mobile,customer_id)VALUES(?,?,?,?,?)");
            $stmt->bind_param("ssssi", $address, $postal_code, $phone, $mobile, $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_id = $conn->insert_id;
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE address SET address=?,postal_code=?,phone=?,mobile=? WHERE id=? AND customer_id=?");
            $stmt->bind_param("ssssii", $address, $postal_code, $phone, $mobile, $id, $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        }
        $conn->close();
        return $response->withJson(["id" => $id, "address" => $address, "postal_code" => $postal_code, "phone" => $phone, "mobile" => $mobile, "customer_id" => $customer_id], 200);
    }
})->setName('save_address');

$app->get('/remove_address', function (Request $request, Response $response, $args) use ($twig, $app) {
    if (!isLogged()) {
        return $response->withJson(["error" => "ابتدا باید وارد شوید"], 500);
    } else {
        $id = intval($request->getQueryParams()["id"]);
        $conn = getConnection();
        $iid = (int) $id;
        $stmt = $conn->prepare("DELETE FROM address WHERE id=? AND customer_id=?");
        $stmt->bind_param("ii", $iid, getCurrentUser()["id"]);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return $response->withJson(["id" => $id], 200);
    }
})->setName('remove_address');

$app->get('/logout', function (Request $request, Response $response, $args) use ($twig, $app) {
    session_unset();
    session_destroy();
    return $response->withRedirect($app->getContainer()->get('router')->pathFor('home'));
})->setName('logout');


$app->run();

function getProductDetails($conn, $productId) {
    global $settings;
    $stmt = $conn->prepare("SELECT product.*,category.name as category_name FROM product LEFT JOIN category ON product.category_id=category.id WHERE product.id=?");
    $id = intval($productId);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    $product["formatted_price"] = number_format($product["sell_price"], 0, ".", ",");
    $categId = $product["category_id"];
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM image WHERE product_id=? ORDER BY position");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $images = array();
    $imageList = array();
    while ($row = $result->fetch_assoc()) {
        array_push($images, $row);
        array_push($imageList, "large/" . str_replace(" ", "-", $product["name"]) . "-" . $row["id"] . "-jpg");
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT product_field.*,field.*,field_value.value AS enum_value FROM `product_field` LEFT JOIN field ON product_field.field_id=field.id  LEFT JOIN field_value ON value_id=field_value.id WHERE product_id=? ORDER BY position");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fields = array();
    while ($row = $result->fetch_assoc()) {
        array_push($fields, $row);
    }
    $stmt->close();
    return ["product" => $product, "fields" => $fields, "images" => $images, "image_list" => $imageList, "common" => $settings, "tree" => getTree($conn), "related" => getRelatedProducts($conn, $categId), "user" => getCurrentUser()];
}

// SELECT DISTINCT(product_field.field_id) FROM product_field LEFT JOIN product ON product.id=product_field.product_id LEFT JOIN category on product.category_id=category.id WHERE product.category_id=12
function getRelatedProducts($conn, $categ_id) {
    global $settings;
    $stmt = $conn->prepare("SELECT product.*,image.id AS image_id FROM product LEFT JOIN image ON image.product_id=product.id AND cover=1 WHERE disabled=0 AND category_id=? ORDER BY RAND() LIMIT ?");
    $stmt->bind_param("ii", intval($categ_id), intval($settings["max_related_products"]));
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        $row["formatted_price"] = number_format($row["sell_price"], 0, ".", ",");
        array_push($list, $row);
    }
    $stmt->close();
    return$list;
}

function filterCategoryProducts($conn, $categ_id, $sort_field, $sort_direction, $min_price, $max_price, $rangeFilters, $valueFilters) {
    $list = array();
    if (($sort_direction != "asc" && $sort_direction != "desc") || ($sort_field != "sell_price" && $sort_field != "buy_count" && $sort_field != "id")) {
        $sort_field = "id";
        $sort_direction = "desc";
    }
    $filterPart = "";
    if (!empty($valueFilters)) {
        foreach ($valueFilters as $item) {
            $filterPart = $filterPart . "AND product.id IN (SELECT product_field.product_id FROM product_field WHERE product_field.field_id=" . $item["id"] . " AND product_field.value_id IN (" . $item["values"] . ")) ";
        }
    }
    if (!empty($rangeFilters)) {
        foreach ($rangeFilters as $item) {
            $filterPart = $filterPart . "AND product.id IN (SELECT product_field.product_id FROM product_field WHERE product_field.field_id=" . $item["id"] . " AND product_field.numeric_value>=" . $item["from"] . " AND product_field.numeric_value<=" . $item["to"] . ") ";
        }
    }
    $query = "SELECT product.*,image.id AS image_id FROM product LEFT JOIN image ON image.product_id=product.id AND cover=1 WHERE disabled=0 AND sell_price>=? AND sell_price<=? AND (category_id=? OR category_id IN (SELECT child_id FROM category_children WHERE id=?)) " . $filterPart . " ORDER BY " . $sort_field . " " . $sort_direction;
    $stmt = $conn->prepare($query);
    $id = intval($categ_id);
    $stmt->bind_param("iiii", $min_price, $max_price, $id, $id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row["formatted_price"] = number_format($row["sell_price"], 0, ".", ",");
        array_push($list, $row);
    }
    $stmt->close();
    return $list;
}

function getCategoryProducts($conn, $categ_id) {
    $list = array();
    $stmt = $conn->prepare("SELECT product.*,image.id AS image_id FROM product LEFT JOIN image ON image.product_id=product.id AND cover=1 WHERE disabled=0 AND (category_id=? OR category_id IN (SELECT child_id FROM category_children WHERE id=?)) ORDER BY id DESC");
    $id = intval($categ_id);
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row["formatted_price"] = number_format($row["sell_price"], 0, ".", ",");
        array_push($list, $row);
    }
    $stmt->close();
    return $list;
}

function getMinMax($conn, $categ_id) {
    $stmt = $conn->prepare("SELECT  min(sell_price) AS min,max(sell_price) AS max FROM product LEFT JOIN image ON image.product_id=product.id AND cover=1 WHERE disabled=0 AND (category_id=? OR category_id IN (SELECT child_id FROM category_children WHERE id=?))");
    $id = intval($categ_id);
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function getTree($conn) {
    $stmt = $conn->prepare("SELECT * FROM category");
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        array_push($list, $row);
    }
    $stmt->close();
    return buildTree($list);
}

function buildTree($items) {
    $children = array();
    foreach ($items as &$item) {
        $children[$item["parent"]][] = &$item;
        unset($item);
    }
    foreach ($items as &$item) {
        if (isset($children[$item["id"]])) {
            $item["children"] = $children[$item["id"]];
        }
    }
    return $children[""];
}

function startsWith($string, $startString) {
    $len = strlen($startString);
    return (substr($string, 0, $len) === $startString);
}

function endsWith($string, $startString) {
    $len = strlen($startString);
    return (substr($string, -$len) === $startString);
}

function getConnection() {
    $conn = new mysqli(HOST, USER, PASSWORD, DB_NAME);
    $conn->set_charset("utf8");
    if ($conn->connect_error) {
        die("Connection faild: " . $conn->connect_error);
    }
    return $conn;
}

function getNewProducts($conn) {
    global $settings;
    $stmt = $conn->prepare("SELECT product.*,image.id AS image_id FROM product LEFT JOIN image ON image.product_id=product.id AND cover=1 WHERE disabled=0 ORDER BY id DESC LIMIT ?");
    $stmt->bind_param("i", intval($settings["num_new_products"]));
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        $row["formatted_price"] = number_format($row["sell_price"], 0, ".", ",");
        array_push($list, $row);
    }
    $stmt->close();
    return $list;
}

function getHomeCategories($conn) {
    global $settings;
    $minCount = intval($settings["home_categs_min_products"]);
    $maxCount = intval($settings["home_categs_product_count"]);
    $stmt = $conn->prepare("SELECT product.id AS product_id,product.name AS product_name,sell_price,code,category.id AS category_id,category.name AS category_name,image.id AS image_id FROM product LEFT JOIN category ON product.category_id=category.id LEFT JOIN image ON product.id=image.product_id AND image.cover=1 WHERE category.id IN(" . $settings["home_categs"] . ")");
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($row = $result->fetch_assoc()) {
        $row["formatted_price"] = number_format($row["sell_price"], 0, ".", ",");
        array_push($list, $row);
    }
    $stmt->close();
    $grouped = groupBy($list, "category_id");
    $res = array();
    foreach ($grouped as $key => $value) {
        $count = count($value);
        if ($count >= $minCount) {
            array_push($res, array_slice($value, 0, min($count, $maxCount)));
        }
    }
    return $res;
}

function groupBy($array, $key) {
    $res = array();
    foreach ($array as $val) {
        $res[$val[$key]][] = $val;
    }
    return $res;
}

function loadSettings($conn) {
    global $settings;
    $stmt = $conn->prepare("SELECT * FROM settings");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $settings[$row["param"]] = $row["value"];
    }
    $stmt->close();
}

function getCurrentUser() {
    if (isset($_SESSION["user"])) {
        return $_SESSION["user"];
    }
    return null;
}

function isLogged() {
    return getCurrentUser() != null;
}

function hashPassword($pass) {
    return md5("eOTuoNYjuvxLwKKgx6byaaZ06iG2qWG3gjR89KHt48WkRNaweFBxvEZS" . $pass);
}

function setCurrentUser($row) {
    $_SESSION["user"] = $row;
}

function updateCurrentUser($fname, $lname, $email, $mobile) {
    $tmp = getCurrentUser();
    $tmp["fname"] = $fname;
    $tmp["lname"] = $lname;
    $tmp["email"] = $email;
    $tmp["mobile"] = $mobile;
    $_SESSION["user"] = $tmp;
}

function sendMail($to, $message, $title, $twig) {
    global $settings;
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: <info@' + APP_SITE + '>' . "\r\n";
    $body = $twig->render('mail.twig', ["title" => $title, "message" => $message, "app_name" => $settings["app_name"], "app_site" => $settings["app_domain"]]);
    mail($to, $title, $body, $headers);
}

function randomPassword($len) {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $pass = array();
    $alphaLength = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $n = rand(0, $alphaLength);
        $pass[] = $alphabet[$n];
    }
    return implode($pass);
}

function getProductPrice($conn, $id) {
    $stmt = $conn->prepare("SELECT sell_price FROM product WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $res = $row["sell_price"];
    $stmt->close();
    return $res;
}
