/// <reference types="node" />
export interface Spectrum { amplitude: number; rms: number }
export interface CompositeChannel {
    video: Spectrum; music: Spectrum; attack: Spectrum; release: Spectrum;
    afterVideo: Spectrum; musicAlone: Spectrum; tail: Spectrum; trimMarker: Spectrum;
}
export interface CompositeMeasurements { left: CompositeChannel; right: CompositeChannel; channelDifferenceRms: number }
export function stereoWave(): Buffer;
export function measureCompositeAudio(bytes: Buffer): CompositeMeasurements;
export function assertCompositeAudio(measurements: CompositeMeasurements): void;
