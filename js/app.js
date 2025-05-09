// swiper sliders
if($('.s-news-wrap .sn-main').length) {
	const swiper = new Swiper('.s-news-wrap .sn-main .swiper', {
		slidesPerView: 'auto',
		spaceBetween: 10,
		breakpoints: {
			480: {
				spaceBetween: 11,
			}
		}
	});
}

if($('.s-news-wrap .sn-detail .sn-btm .snb-text .snb-t-slider .swiper').length) {
	const swiper = new Swiper('.s-news-wrap .sn-detail .sn-btm .snb-text .snb-t-slider .swiper', {
		slidesPerView: 'auto',
		spaceBetween: 15,
		breakpoints: {
			768: {
				spaceBetween: 30,
			}
		},
		pagination: {
			el: '.s-news-wrap .sn-detail .sn-btm .snb-text .snb-t-slider .swiper-pagination',
			clickable: true,
		},
		navigation: {
			nextEl: '.s-news-wrap .sn-detail .sn-btm .snb-text .snb-t-slider .swiper-button-next',
			prevEl: '.s-news-wrap .sn-detail .sn-btm .snb-text .snb-t-slider .swiper-button-prev',
		},
	});
}

if($('.s-news-wrap .sn-also').length) {
	const swiper = new Swiper('.s-news-wrap .sn-also .sna-block .sna-top .sna-nav-slider .swiper', {
		slidesPerView: 'auto',
		spaceBetween: 10,
		breakpoints: {
			480: {
				spaceBetween: 11,
			}
		},
	});

	const mainSliders = document.querySelectorAll('.s-news-wrap .sn-also .sna-block .sna-slider-wrap .sna-slider');
	const slidersArr = [];
	mainSliders.forEach((sldr) => {
		const slider = new Swiper(sldr.querySelector('.swiper'), {
			slidesPerView: 'auto',
			spaceBetween: 15,
			breakpoints: {
				768: {
					spaceBetween: 30,
				}
			},
			navigation: {
				nextEl: '.s-news-wrap .sn-also .sna-block .sna-top .def-arrows .swiper-button-next',
				prevEl: '.s-news-wrap .sn-also .sna-block .sna-top .def-arrows .swiper-button-prev',
			},
			pagination: {
				el: sldr.querySelector('.swiper-pagination'),
				clickable: true,
			},
		});

		slidersArr.push(slider);
	})
	
	$('.s-news-wrap .sn-also .sna-block .sna-top .sna-nav-slider .swiper-slide').click(function() {
		const idx = $(this).index();

		$('.s-news-wrap .sn-also .sna-block .sna-top .sna-nav-slider .swiper-slide').removeClass('active');
		$(this).addClass('active');

		$('.s-news-wrap .sn-also .sna-block .sna-slider-wrap .sna-slider').removeClass('active').eq(idx).addClass('active');
		setTimeout(() => {
			slidersArr[idx].update();
			slidersArr[idx].slideTo(0);
		}, 300)
	});
}

if($('.s-news-wrap .sn-detail .sn-btm .snb-right .snb-r-card').length && $(window).width() > 768) {
	const offsetTop = document.querySelector('.s-news-wrap .sn-detail .sn-btm').offsetTop;
	const blockHeight = document.querySelector('.s-news-wrap .sn-detail .sn-btm').offsetHeight;
	const cardHeight =document.querySelector('.s-news-wrap .sn-detail .sn-btm .snb-right .snb-r-card').offsetHeight;

	$(window).scroll(function() {
		let sc = $(this).scrollTop();

		if(sc >= offsetTop && (sc - offsetTop) <= (blockHeight - cardHeight)) {
			$('.s-news-wrap .sn-detail .sn-btm .snb-right .snb-r-card').css('top', `${sc - offsetTop}px`);
		}

	});
}

// open hidden items
$('.s-news-wrap .sn-main .sd-items .sdi-btn-wrap .btn').click(function(e) {
	e.preventDefault();

	$(this).parent().addClass('disable');
	$('.s-news-wrap .sn-main .sd-items .sdi-itm.hidden').removeClass('hidden');
});