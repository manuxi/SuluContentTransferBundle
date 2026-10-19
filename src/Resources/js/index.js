import {listToolbarActionRegistry} from 'sulu-admin-bundle/views';
import ExportToolbarAction from './listToolbarActions/ExportToolbarAction';

listToolbarActionRegistry.add('sulu_content_transfer.export', ExportToolbarAction);
