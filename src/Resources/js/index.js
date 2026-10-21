import {listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import ExportToolbarAction from './listToolbarActions/ExportToolbarAction';
import ImportToolbarAction from './listToolbarActions/ImportToolbarAction';
import TransferToolbarAction from './listToolbarActions/TransferToolbarAction';

// the dropdown that the article lists show; the two single buttons stay registered for projects that add them themselves
listToolbarActionRegistry.add('sulu_content_transfer.transfer', TransferToolbarAction);
listToolbarActionRegistry.add('sulu_content_transfer.export', ExportToolbarAction);
listToolbarActionRegistry.add('sulu_content_transfer.import', ImportToolbarAction);
