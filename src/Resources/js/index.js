import {listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import ExportToolbarAction from './listToolbarActions/ExportToolbarAction';
import ImportToolbarAction from './listToolbarActions/ImportToolbarAction';

listToolbarActionRegistry.add('sulu_content_transfer.export', ExportToolbarAction);
listToolbarActionRegistry.add('sulu_content_transfer.import', ImportToolbarAction);
