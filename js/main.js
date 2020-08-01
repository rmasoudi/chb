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
            hideOnPageScroll:true
        }
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
});

function addToCart(product) {
    let cart = getCart();
    if (cart[product.id] === null || cart[product.id] === undefined) {
        cart[product.id] = product;
    } else {
        let oldAmount = cart[product.id].amount;
        oldAmount += product.amount;
        cart[product.id].amount = oldAmount;
    }
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function changeProductCount(productId, amount) {
    let cart = getCart();
    cart[productId].amount = amount;
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function removeFromCart(productId) {
    let cart = getCart();
    delete cart[productId];
    localStorage.setItem("cart", JSON.stringify(cart));
    updateCount();
}

function updateCount() {
    let cart = getCart();
    let sum = 0;
    for (let key in cart) {
        sum += cart[key].amount;
    }
    if (sum > 0) {
        $("#cartCount").html(sum.toString());
        $("#cartCount").removeClass("hidden");
        $("#cartCount").addClass("blink");
    }
    else{
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