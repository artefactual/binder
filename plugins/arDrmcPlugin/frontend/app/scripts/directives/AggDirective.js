(function () {

  'use strict';

  angular.module('drmc.directives').directive('arAgg', function (SETTINGS) {

    return {
      restrict: 'E',
      templateUrl: SETTINGS.viewsPath + '/partials/agg.html',
      replace: true,
      scope: {
        label: '@',
        agg: '=',
        field: '='
      },
      link: function (scope) {
        scope.collapsed = false;

        scope.toggle = function () {
          scope.collapsed = !scope.collapsed;
        };

        scope.select = function (id) {
          // Empty filter if All is clicked
          if (typeof id === 'undefined') {
            scope.field = [];
            return;
          }
          // Create array if undefined
          if (typeof scope.field === 'undefined') {
            scope.field = [id];
            return;
          }
          // Update scope.field
          var index = jQuery.inArray(id, scope.field);
          if (index === -1) {
            scope.field.push(id);
          } else {
            scope.field.splice(index, 1);
          }
        };

        scope.isSelected = function (id) {
          if (typeof id === 'undefined') {
            return typeof scope.field === 'undefined' || scope.field.length === 0;
          }
          return jQuery.inArray(id, scope.field) !== -1;
        };
      }
    };

  });

})();
