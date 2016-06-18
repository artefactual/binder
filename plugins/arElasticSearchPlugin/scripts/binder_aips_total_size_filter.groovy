sum = 0
doc['aips.sizeOnDisk'].values.each {
  sum += it
}
if (binding.variables.containsKey('from') && binding.variables.containsKey('to'))
  sum > from && sum < to
else if (binding.variables.containsKey('from'))
  sum > from
else if (binding.variables.containsKey('to'))
  sum < to
