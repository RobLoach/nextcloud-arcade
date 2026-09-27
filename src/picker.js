// The file picker and its stylesheet travel together: importing the
// styles here, statically, folds them into this chunk, where they are
// injected the moment the chunk loads. Imported dynamically from the
// settings page instead, the stylesheet would be emitted as a loose
// file into css/, which holds handwritten stylesheets only.
import '@nextcloud/dialogs/style.css'

export { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
