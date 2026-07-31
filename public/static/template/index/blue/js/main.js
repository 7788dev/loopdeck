/***************************************************
==================== JS INDEX ======================
****************************************************
01. mobile menu
02. testimonial active
03. team active
04. choose active
05. Home page 3 testimonial
06. Home page 2 testimonial
07. data background
08. wow active
09. sidebar active

****************************************************/

(function ($) {
"use strict";

/*

****************************************************
01. mobile menu
****************************************************
*/ 

$('#mobile-menu').meanmenu({
	meanMenuContainer: '.mobile-menu',
	meanScreenWidth: "992"
});

$('.side-toggle').on('click', function () {
	$('.side-info').addClass('info-open');
	$('.offcanvas-overlay').addClass('overlay-open');
})

$('.side-info-close,.offcanvas-overlay').on('click', function () {
	$('.side-info').removeClass('info-open');
	$('.offcanvas-overlay').removeClass('overlay-open');
})

// One Page Nav
var top_offset = $('.header-area').height() - 10;
$('.main-menu nav ul').onePageNav({
	currentClass: 'active',
	scrollOffset: top_offset,
});


$(window).on('scroll', function () {
	var scroll = $(window).scrollTop();
	if (scroll < 245) {
		$(".header-sticky").removeClass("sticky");
	} else {
		$(".header-sticky").addClass("sticky");
	}
});

/*

****************************************************
02. testimonial active
****************************************************
*/ 


$('.testimonial-active').slick({
	infinite: true,
	slidesToShow: 3,
	slidesToScroll: 2,
	arrows: false,
	dots:true,
	responsive: [
        {
            breakpoint: 1024,
            settings: {
                slidesToShow: 3,
                slidesToScroll: 3,
            }
        },
        {
            breakpoint: 992,
            settings: {
                slidesToShow: 2,
                slidesToScroll: 2
            }
        },
        {
            breakpoint: 480,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        },
		{
            breakpoint: 576,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        },
		{
            breakpoint: 768,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        }

  ]
  });

/*

****************************************************
03. team active
****************************************************
*/ 

  $('.team-active').slick({
	infinite: true,
	slidesToShow: 3,
	slidesToScroll: 2,
	arrows: false,
	dots:true,
	responsive: [
        {
            breakpoint: 1024,
            settings: {
                slidesToShow: 3,
                slidesToScroll: 3,
            }
        },
        {
            breakpoint: 992,
            settings: {
                slidesToShow: 2,
                slidesToScroll: 2
            }
        },
        {
            breakpoint: 768,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        },
		        {
            breakpoint: 576,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        }

  ]
  });

/*

****************************************************
04. choose active
****************************************************
*/ 

  $('.choose_active').slick({
	infinite: true,
	slidesToShow: 5,
	slidesToScroll: 2,
	arrows: true,
	dots:false,
	prevArrow: '<button type="button" class="slick-prev"><i class="fal fa-long-arrow-left"></i></button>',
	nextArrow: '<button type="button" class="slick-next"><i class="fal fa-long-arrow-right"></i></button>',
	responsive: [
        {
            breakpoint: 1024,
            settings: {
                slidesToShow: 3,
                slidesToScroll: 3,
            }
        },
        {
            breakpoint: 992,
            settings: {
                slidesToShow: 3,
                slidesToScroll: 2
            }
        },
        {
            breakpoint: 768,
            settings: {
                slidesToShow: 2,
                slidesToScroll: 1
            }
        },
		        {
            breakpoint: 576,
            settings: {
                slidesToShow: 1,
                slidesToScroll: 1
            }
        }

  ]
  });
  
/*

****************************************************
05. Home page 3 testimonial
****************************************************
*/ 

  $('.testimonialh3_active').slick({
	infinite: true,
	slidesToShow: 1,
	slidesToScroll: 1,
	arrows: true,
	dots:false,
	prevArrow: '<button type="button" class="slick-prev"><i class="fal fa-long-arrow-left"></i></button>',
	nextArrow: '<button type="button" class="slick-next"><i class="fal fa-long-arrow-right"></i></button>',
  });

  $('.testimonial-active2').slick({
	infinite: true,
	slidesToShow: 1,
	slidesToScroll: 1,
	arrows: true,
	dots:false,
	prevArrow: '<button type="button" class="slick-prev"><i class="fal fa-long-arrow-left"></i></button>',
	nextArrow: '<button type="button" class="slick-next"><i class="fal fa-long-arrow-right"></i></button>',
  });

/*

****************************************************
06. Home page 2 testimonial
****************************************************
*/ 

$('.slider-for').slick({
	slidesToShow: 1,
	slidesToScroll: 1,
	arrows: false,
	fade: false,
	infinite: true,
	asNavFor: '.slider-nav',
  });
  $('.slider-nav').slick({
	slidesToShow: 1,
	slidesToScroll: 1,
	asNavFor: '.slider-for',
	dots: false,
	centerMode: false,
	focusOnSelect: false,
	arrows:true,
	prevArrow: '<button type="button" class="slick-prev"><i class="fal fa-long-arrow-left"></i></button>',
	nextArrow: '<button type="button" class="slick-next"><i class="fal fa-long-arrow-right"></i></button>',

  });

/*

****************************************************
07. data background
****************************************************
*/ 
  $("[data-background").each(function () {
	$(this).css("background-image", "url( " + $(this).attr("data-background") + "  )");
});

		  
// mainSlider
function mainSlider() {
	var BasicSlider = $('.slider-active');
	BasicSlider.on('init', function (e, slick) {
		var $firstAnimatingElements = $('.single-slider:first-child').find('[data-animation]');
		doAnimations($firstAnimatingElements);
	});
	BasicSlider.on('beforeChange', function (e, slick, currentSlide, nextSlide) {
		var $animatingElements = $('.single-slider[data-slick-index="' + nextSlide + '"]').find('[data-animation]');
		doAnimations($animatingElements);
	});
	BasicSlider.slick({
		autoplay: false,
		autoplaySpeed: 10000,
		dots: false,
		fade: true,
		arrows: false,
		responsive: [
			{ breakpoint: 767, settings: { dots: false, arrows: false } }
		]
	});

	function doAnimations(elements) {
		var animationEndEvents = 'webkitAnimationEnd mozAnimationEnd MSAnimationEnd oanimationend animationend';
		elements.each(function () {
			var $this = $(this);
			var $animationDelay = $this.data('delay');
			var $animationType = 'animated ' + $this.data('animation');
			$this.css({
				'animation-delay': $animationDelay,
				'-webkit-animation-delay': $animationDelay
			});
			$this.addClass($animationType).one(animationEndEvents, function () {
				$this.removeClass($animationType);
			});
		});
	}
}
mainSlider();


/*

****************************************************
08. wow active
****************************************************
*/ 

new WOW().init();

/*

****************************************************
09. sidebar active
****************************************************
*/ 
$('button.menu-expand').on('click',function(){
	$('.responsive-header').addClass('menu-open');
	$('.overlay').addClass('show-overlay');
})
$('.overlay').on('click',function(){
	$('.responsive-header').removeClass('menu-open');
	$('.overlay').removeClass('show-overlay');
})


})(jQuery);