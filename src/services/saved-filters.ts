import Vue from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { bus } from '@services/utils/event-bus';
import staticConfig from '@services/static-config';

import type { IFilters } from '@typings';

export interface SavedFilter {
  id: string;
  name: string;
  filters: IFilters;
}

class SavedFilters {
  private data = Vue.observable<{ list: SavedFilter[] }>({ list: [] });
  private initialized = false;

  public async init() {
    if (this.initialized) return;
    this.initialized = true;

    const raw = await staticConfig.get('saved_filters');
    try {
      this.data.list = JSON.parse(raw || '[]');
    } catch {
      // ignore malformed blob, start fresh
    }
  }

  public get list(): SavedFilter[] {
    return this.data.list;
  }

  public save(name: string, filters: IFilters): SavedFilter {
    const entry: SavedFilter = { id: crypto.randomUUID(), name, filters };
    this.data.list = [...this.data.list, entry];
    this.persist();
    return entry;
  }

  public remove(id: string) {
    this.data.list = this.data.list.filter((f) => f.id !== id);
    this.persist();
  }

  private persist() {
    const value = JSON.stringify(this.data.list);
    staticConfig.setLs('saved_filters', value);
    axios.put(API.CONFIG('savedFilters'), { value }).catch(() => {
      // best-effort; local reactive state already reflects the change
    });
    bus.emit('memories:user-config-changed', { setting: 'saved_filters', value });
  }
}

export default new SavedFilters();
