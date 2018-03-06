(function () {

  'use strict';

  angular.module('drmc.directives').directive('arRangeAgg', function (SETTINGS) {
    return {
      restrict: 'E',
      templateUrl: SETTINGS.viewsPath + '/partials/range-agg.html',
      replace: true,
      scope: {
        type: '@',
        label: '@',
        agg: '=',
        from: '=',
        to: '='
      },
      link: function (scope) {
        scope.collapsed = false;
        scope.collapsedRangePicker = true;
        scope.dateRangePickerFrom = undefined;
        scope.dateRangePickerTo = undefined;
        scope.sizeRangePickerFrom = undefined;
        scope.sizeRangePickerTo = undefined;
        scope.units = [
          { label: 'bytes', value: 1 },
          { label: 'KB', value: 1024 },
          { label: 'MB', value: 1048576 },
          { label: 'GB', value: 1073741824 },
          { label: 'TB', value: 1099511627776 },
          { label: 'PB', value: 1125899906842624 }
        ];
        scope.sizeRangePickerFromUnit = scope.units[0];
        scope.sizeRangePickerToUnit = scope.units[0];

        scope.toggle = function () {
          scope.collapsed = !scope.collapsed;
        };

        scope.toggleRangePicker = function () {
          scope.collapsedRangePicker = !scope.collapsedRangePicker;
        };

        scope.select = function (from, to) {
          if (scope.from === from && scope.to === to) {
            scope.from = undefined;
            scope.to = undefined;
            return;
          }
          scope.from = from;
          scope.to = to;
        };

        scope.isSelected = function (from, to) {
          return scope.from === from && scope.to === to;
        };

        scope.resetRangePicker = function () {
          scope.sizeRangePickerFrom = scope.dateRangePickerFrom = scope.from = undefined;
          scope.sizeRangePickerTo = scope.dateRangePickerTo = scope.to = undefined;
          scope.sizeRangePickerFromUnit = scope.units[0];
          scope.sizeRangePickerToUnit = scope.units[0];
        };

        scope.submitRangePicker = function () {
          if (scope.type === 'date') {
            if (scope.dateRangePickerFrom !== undefined) {
              scope.from = new Date(scope.dateRangePickerFrom).getTime();
            } else {
              scope.from = scope.dateRangePickerFrom = undefined;
            }
            if (scope.dateRangePickerTo !== undefined) {
              scope.to = new Date(scope.dateRangePickerTo).getTime();
            } else {
              scope.to = scope.dateRangePickerTo = undefined;
            }
          }
          if (scope.type === 'size') {
            if (scope.sizeRangePickerFrom !== undefined && !isNaN(scope.sizeRangePickerFrom)) {
              scope.from = parseInt(scope.sizeRangePickerFrom) * scope.sizeRangePickerFromUnit.value;
            } else {
              scope.from = scope.sizeRangePickerFrom = undefined;
            }
            if (scope.sizeRangePickerTo !== undefined && !isNaN(scope.sizeRangePickerTo)) {
              scope.to = parseInt(scope.sizeRangePickerTo) * scope.sizeRangePickerToUnit.value;
            } else {
              scope.to = scope.sizeRangePickerTo = undefined;
            }
          }
          if (scope.type === 'dateYear') {
            if (scope.dateRangePickerFrom !== undefined && scope.dateRangePickerFrom.match(/\d{4}/)) {
              scope.from = new Date(scope.dateRangePickerFrom).getTime();
            } else {
              scope.from = scope.dateRangePickerFrom = undefined;
            }
            if (scope.dateRangePickerTo !== undefined && scope.dateRangePickerTo.match(/\d{4}/)) {
              scope.to = new Date(scope.dateRangePickerTo).getTime();
            } else {
              scope.to = scope.dateRangePickerTo = undefined;
            }
          }
        };
      }
    };

  });

})();
