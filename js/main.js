myVar = null;
lastMotion = 0;
lastLoadTime = 0;
currentImageTime = 0;
timeDiff = 0;
checkingLocalMode = false;
localMode = false;
socket = null;
loadingImage = false;
app = null;
$$ = null;
$(document).ready(function () {
    $$ = Dom7;
    app = new Framework7({
        root: '#app',
        name: 'چوبی سنتر',
        id: 'ir.choobicenter',
        panel: {
            swipe: 'left',
        },
        routes: [],
        navbar: {
            mdCenterTitle: true,
            hideOnPageScroll: true
        }
    });
    loginPop = app.popup.create({
        el: '.loginPop'
    });
    app.swiper.create('.home-slider', {
        speed: 400,
        loop: true,
        autoplay: {
            delay: 3000
        }
    });
    app.swiper.create('.product-slider', {
        speed: 400,
        loop: true,
        slidesPerView: 1,
        spaceBetween: 10,
        breakpoints: {
            320: {
                slidesPerView: 2,
                spaceBetween: 20
            },
            480: {
                slidesPerView: 3,
                spaceBetween: 30
            },
            640: {
                slidesPerView: 4,
                spaceBetween: 40
            },
            768: {
                slidesPerView: 5,
                spaceBetween: 50
            },
            992: {
                slidesPerView: 6,
                spaceBetween: 60
            }
        }
    });
    app.sheet.create({
        el: '.contact-sheet',
        swipeToClose: true,
        backdrop: true,
        closeByOutsideClick: true
    });
    app.stepper.create({
        el: "#amountStepper",
        min: 1,
        max: 15,
        value: 1
    });
    $("#btnCartBuy").click(function () {
        var login = $("#btnCartBuy").data().login;
        if (login) {
            window.location = "order";
        } else {
            loginPop.open();
        }
    });

    updateCount();
    app.autocomplete.create({
        inputEl: '#query',
        openIn: 'dropdown',
        preloader: true,
        valueProperty: 'id',
        textProperty: 'text',
        updateInputValueOnSelect: false,
        limit: 8,
        on: {
            change: function (value) {
                var id = value[0].id;
                var name = value[0].name;
                window.location = name.replaceAll(" ", "-") + "-" + id + "-htm";
            }
        },
        source: function (query, render) {
            var autocomplete = this;
            var results = [];
            if (query.length === 0) {
                render(results);
                return;
            }
            autocomplete.preloaderShow();
            app.request({
                url: 'search/' + query,
                method: 'GET',
                dataType: 'json',
                success: function (data) {
                    for (var i = 0; i < data.length; i++) {
                        if (data[i].name.toLowerCase().indexOf(query.toLowerCase()) >= 0)
                            results.push(data[i]);
                    }
                    autocomplete.preloaderHide();
                    render(results);
                }
            });
        }
    });
    $$(".search-sheet").on("sheet:open", function () {
        $("#query").focus();
    });
    $$(".loginPop").on("popup:open", function () {
        $("#loginBlock").removeClass("hidden");
        $("#registerBlock").addClass("hidden");
        $("#forgetBlock").addClass("hidden");
        $("#txtLoginEmail").focus();
    });
    $$(".panel-left-1").on("panel:open", function () {
        updateCart($("#cartItems"), $("#cartTotal"));
    });
    $("#btnGoRegister").click(function () {
        $("#loginBlock").addClass("hidden");
        $("#forgetBlock").addClass("hidden");
        $("#registerBlock").removeClass("hidden");
        $("#txtFname").focus();
    });
    $("#btnGoForget").click(function () {
        $("#loginBlock").addClass("hidden");
        $("#forgetBlock").removeClass("hidden");
        $("#registerBlock").addClass("hidden");
        $("#txtForgetEmail").focus();
    });
    $(".backLogin").click(function () {
        $("#loginBlock").removeClass("hidden");
        $("#registerBlock").addClass("hidden");
        $("#forgetBlock").addClass("hidden");
        $("#txtLoginEmail").focus();
    });
    $("#btnLogin").click(function () {
        $.ajax({
            type: "POST",
            url: "dologin",
            data: $("#loginForm").serialize(),
            success: function (response) {
                location.reload();
            },
            error: function (response) {
                $("#loginMessage").html(response.responseJSON.error);
            }
        });
    });
    $("#btnRegister").click(function () {
        $.ajax({
            type: "POST",
            url: "save_user",
            data: $("#registerForm").serialize(),
            success: function (response) {
                location.reload();
            },
            error: function (response) {
                $("#registerMessage").html(response.responseJSON.error);
            }
        });
    });
    $("#btnForget").click(function () {
        $.ajax({
            type: "POST",
            url: "forget",
            data: $("#forgetForm").serialize(),
            success: function (response) {
                app.popup.get(".loginPop").close();
                notify("لینک تغییر رمز به ایمیل شما ارسال شد.")
            },
            error: function (response) {
                $("#forgetMessage").html(response.responseJSON.error);
            }
        });
    });
});

function addToCart(product) {
    var cart = getCart();
    if (cart[product.id] === null || cart[product.id] === undefined) {
        cart[product.id] = product;
    } else {
        var oldAmount = cart[product.id].amount;
        oldAmount += product.amount;
        cart[product.id].amount = oldAmount;
    }
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function changeProductCount(productId, amount) {
    var cart = getCart();
    cart[productId].amount = amount;
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function removeFromCart(productId) {
    var cart = getCart();
    delete cart[productId];
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function updateCount() {
    var cart = getCart();
    var sum = 0;
    for (var key in cart) {
        sum += cart[key].amount;
    }
    if (sum > 0) {
        $("#cartCount").html(sum.toString());
        $("#cartCount").removeClass("hidden");
        $("#cartCount").addClass("blink");
    } else {
        $("#cartCount").addClass("hidden");
        $("#cartCount").removeClass("blink");
    }
}

function getCart() {
    if (localStorage.getItem("cart") === null || localStorage.getItem("cart") === undefined) {
        return {};
    }
    return JSON.parse(localStorage.getItem("cart"));
}

function setAddress(address) {
    localStorage.setItem("address", JSON.stringify(address));
}

function getAddress(address) {
    if (localStorage.getItem("address") === null || localStorage.getItem("address") === undefined) {
        return null;
    }
    return JSON.parse(localStorage.getItem("address"));
}


function updateCart(cartContainer, totalContainer) {
    var cart = getCart();
    cartContainer.html("");
    var sum = 0;
    for (var key in cart) {
        var product = cart[key];
        sum += (product.sell_price * product.amount);
        var li = $("<li></li>");
        var a = $("<a href='#' class='item-content'></a>");
        var img = $("<div class='item-media'><img alt='" + product.name + "' title='" + product.name + "' src='" + product.image + "' width='80'/></div>");
        var inner = $("<div class='item-inner'></div>");
        var row = $("<div class='item-title-row'></div>");
        var title = $("<div class='item-title' style='font-size: 10pt;'>" + product.name + "</div>");
        var body = $("<div class='item-text'>" + formatPrice(product.sell_price * product.amount) + " تومان" + "</div>");
        var stepper = $("<div class='stepper stepper-small stepper-raised' style='margin-top: 5px;'></div>");
        inner.data("productId", product.id);
        var minus = $("<div class='stepper-button-minus'></div>");
        var wrap = $("<div class='stepper-input-wrap'><input type='text' value='" + product.amount + "' readonly/></div>");
        var plus = $("<div class='stepper-button-plus'></div>");
        stepper.append(minus);
        stepper.append(wrap);
        stepper.append(plus);
        row.append(title);
        inner.append(row);
        inner.append(body);
        inner.append(stepper);
        a.append(img);
        a.append(inner);
        li.append(a);
        cartContainer.append(li);
        app.stepper.create({
            el: stepper,
            on: {
                change: function (cc) {
                    var id = $(cc.el).parent().data().productId;
                    if (cc.value > 0) {
                        changeProductCount(id, cc.value);
                        updateCart($("#cartItems"), $("#cartTotal"));
                    } else {
                        removeFromCart(id);
                        updateCart($("#cartItems"), $("#cartTotal"));
                    }
                }
            }
        });
    }
    totalContainer.html("");
    if (sum > 0) {
        var sp1 = $("<span>مبلغ قابل پرداخت: </span>");
        var sp2 = $("<strong style='color: var(--f7-button-text-color,var(--f7-theme-color))'>" + formatPrice(sum) + " تومان</strong>");
        totalContainer.append(sp1);
        totalContainer.append(sp2);
    }
}

function getProducerCount() {
    var cart = getCart();
    var set = new Set()
    for (var key in cart) {
        set.add(cart[key].producer);
    }
    return set.size;
}

function formatPrice(val) {
    return val.toString().replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,");
}

function notify(message) {
    var notification = app.notification.create({
        text: message,
        closeTimeout: 2500,
        closeOnClick: true,
        on: {
            close: function () {
            }
        }

    });
    notification.open();
}