/**
 * Reusable visual filter-builder adapter.
 *
 * Rendering remains compatible with the existing Cacti query builder while
 * consumers choose their own fields, operators, values, and serialization.
 */
class SyslogFilterBuilder {
	constructor(element, options) {
		this.element = element;
		this.options = options || {};
		var labels = this.options.labels || {};

		element.dataset.fields = JSON.stringify(this.options.fields || {});
		element.dataset.operators = JSON.stringify(this.options.operators || {});
		element.dataset.choices = JSON.stringify(this.options.choices || {});
		element.dataset.theme = this.options.theme || 'cacti';
		element.dataset.message = labels.message || 'Value';
		element.dataset.placeholder = labels.placeholder || 'Enter a value';
		element.dataset.match = labels.match || 'Match';
		element.dataset.exclude = labels.exclude || 'Exclude';
		element.dataset.remove = labels.remove || 'Remove condition';
		element.dataset.integer = labels.integer || 'Enter a nonnegative integer';

		initSyslogSearchBuilder(element, this.options.conditions || []);
	}

	validate() {
		return syslogBuilderSync(this.element) !== null;
	}

	toDocument() {
		return {version: 1, conditions: this.element.searchRows};
	}

	serialize() {
		return JSON.stringify(this.toDocument());
	}

	syncTo(input) {
		if (!this.validate()) {
			return false;
		}
		input.value = this.serialize();
		return true;
	}

	setDisabled(disabled) {
		this.element.querySelectorAll('button, input, select').forEach(function(control) {
			control.disabled = disabled;
		});
		$(this.element).find('select').each(function() {
			if ($(this).selectmenu('instance')) {
				$(this).selectmenu('option', 'disabled', disabled);
			}
		});
	}
}
