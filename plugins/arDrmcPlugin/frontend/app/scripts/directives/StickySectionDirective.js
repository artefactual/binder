(function () {

  'use strict';

  angular.module('drmc.directives').directive('arStickySection', function ($window) {

    return {
      scope: {},
      restrict: 'A',
      link: function (scope, element) {
        var w = angular.element($window),
          size = element[0].clientHeight,
          top = 0;

        function toggleStickySection () {
          if (!element.hasClass('sticky-section') && $window.pageYOffset > top + size) {
            element.addClass('sticky-section');
          } else if (element.hasClass('sticky-section') && $window.pageYOffset <= top + size) {
            element.removeClass('sticky-section');
          }
        }

        scope.$watch(function () {
          return element[0].getBoundingClientRect().top + $window.pageYOffset;
        }, function (newValue, oldValue) {
          if (newValue !== oldValue && !element.hasClass('sticky-section')) {
            top = newValue;
          }
        });

        w.bind('resize', function stickySectionResize () {
          element.removeClass('sticky-section');
          top = element[0].getBoundingClientRect().top + $window.pageYOffset;
          toggleStickySection();
        });

        w.bind('scroll', toggleStickySection);
      }
    };

  });

})();
